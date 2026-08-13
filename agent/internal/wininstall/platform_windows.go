//go:build windows

package wininstall

import (
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"syscall"
	"time"
	"unsafe"

	"github.com/go-ole/go-ole"
	"github.com/go-ole/go-ole/oleutil"
	"github.com/mirivlad/mirvmon/agent/internal/atomicfile"
	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

const (
	serviceName   = "MirvMonAgent"
	legacyTask    = "MirvMon Agent"
	serviceWait   = 20 * time.Second
	taskStateRun  = 4
	directorySDDL = "D:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"
	secretSDDL    = "D:P(A;;FA;;;SY)(A;;FA;;;BA)"
	binarySDDL    = "D:P(A;;GRGX;;;SY)(A;;FA;;;BA)"
)

var rtlGetVersion = windows.NewLazySystemDLL("ntdll.dll").NewProc("RtlGetVersion")

type windowsPlatform struct{}

type windowsSnapshot struct {
	files   map[string]fileSnapshot
	service serviceSnapshot
	task    taskSnapshot
}

type fileSnapshot struct {
	existed bool
	backup  string
}

type serviceSnapshot struct {
	existed bool
	config  mgr.Config
	state   svc.State
}

type taskSnapshot struct {
	existed        bool
	enabled        bool
	running        bool
	definitionPath string
}

type rtlVersionInfo struct {
	Size             uint32
	Major            uint32
	Minor            uint32
	Build            uint32
	PlatformID       uint32
	ServicePackName  [128]uint16
	ServicePackMajor uint16
	ServicePackMinor uint16
	SuiteMask        uint16
	ProductType      byte
	Reserved         byte
}

// NewPlatform returns the native Windows implementation.
func NewPlatform() Platform { return windowsPlatform{} }

// DefaultDirectories returns the protected installation and state roots.
func DefaultDirectories() (string, string, error) {
	programFiles := os.Getenv("ProgramW6432")
	if programFiles == "" {
		programFiles = os.Getenv("ProgramFiles")
	}
	programData := os.Getenv("ProgramData")
	if programFiles == "" || programData == "" {
		return "", "", errors.New("Windows installation roots are unavailable")
	}
	return filepath.Join(programFiles, "MirvMon", "Agent"), filepath.Join(programData, "MirvMon", "Agent"), nil
}

func (windowsPlatform) Validate(request Request) error {
	if runtime.GOARCH != "amd64" || !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("elevated x64 process required")
	}
	if filepath.Clean(filepath.Dir(request.BootstrapPath)) != filepath.Clean(filepath.Dir(request.ExecutablePath)) {
		return errors.New("payload files are not adjacent")
	}
	version, err := currentWindowsVersion()
	if err != nil {
		return err
	}
	artifact, ok := ArtifactForWindowsVersion(version)
	if !ok || artifact != request.ExpectedArtifact {
		return errors.New("unsupported Windows release or artifact")
	}
	return protectPath(filepath.Dir(request.BootstrapPath), directorySDDL)
}

func (windowsPlatform) ProtectStage(paths Paths) error {
	return protectPath(paths.StageDir, directorySDDL)
}

func (windowsPlatform) Snapshot(paths Paths) (Snapshot, error) {
	value := &windowsSnapshot{files: make(map[string]fileSnapshot)}
	for name, path := range map[string]string{
		"agent": paths.InstalledAgent, "config": paths.InstalledConfig, "queue": paths.InstalledQueue,
	} {
		backup := filepath.Join(paths.StageDir, "rollback-"+name)
		existed, err := snapshotFile(path, backup)
		if err != nil {
			return Snapshot{}, err
		}
		value.files[path] = fileSnapshot{existed: existed, backup: backup}
	}
	service, err := snapshotService()
	if err != nil {
		return Snapshot{}, err
	}
	value.service = service
	task, err := snapshotTask(filepath.Join(paths.StageDir, "rollback-task.xml"))
	if err != nil {
		return Snapshot{}, err
	}
	value.task = task
	return Snapshot{Value: value}, nil
}

func (windowsPlatform) Protect(paths Paths) error {
	for _, path := range []string{filepath.Dir(paths.InstalledAgent), filepath.Dir(paths.InstalledConfig)} {
		if err := os.MkdirAll(path, 0700); err != nil {
			return err
		}
		if err := protectPath(path, directorySDDL); err != nil {
			return err
		}
	}
	return nil
}

func (windowsPlatform) Freeze(snapshot Snapshot) error {
	state, err := windowsState(snapshot)
	if err != nil {
		return err
	}
	if state.service.existed && state.service.state != svc.Stopped {
		if err := stopService(); err != nil {
			return err
		}
	}
	if state.task.existed {
		if state.task.enabled {
			if err := runSchtasks("/Change", "/TN", legacyTask, "/Disable"); err != nil {
				return err
			}
		}
		if err := runSchtasksAllowed([]int{0, 1}, "/End", "/TN", legacyTask); err != nil {
			return err
		}
		deadline := time.Now().Add(serviceWait)
		for time.Now().Before(deadline) {
			current, err := queryTaskState()
			if err != nil {
				return err
			}
			if !current.running {
				return nil
			}
			time.Sleep(time.Second)
		}
		return errors.New("legacy task did not stop")
	}
	return nil
}

func (windowsPlatform) InstallFiles(paths Paths, snapshot Snapshot) error {
	if _, err := windowsState(snapshot); err != nil {
		return err
	}
	timestamp := time.Now().UTC().Format("20060102150405")
	for _, path := range []string{paths.InstalledAgent, paths.InstalledConfig, paths.SourceQueue} {
		if regularFile(path) {
			if err := copyFile(path, path+".legacy-"+timestamp, 0600); err != nil {
				return err
			}
		}
	}
	if err := copyFile(paths.SelectedAgent, paths.InstalledAgent, 0500); err != nil {
		return err
	}
	if err := copyFile(paths.StagedConfig, paths.InstalledConfig, 0600); err != nil {
		return err
	}
	if err := copyFile(paths.StagedQueue, paths.InstalledQueue, 0600); err != nil {
		return err
	}
	if err := protectPath(paths.InstalledAgent, binarySDDL); err != nil {
		return err
	}
	for _, path := range []string{paths.InstalledConfig, paths.InstalledQueue} {
		if err := protectPath(path, secretSDDL); err != nil {
			return err
		}
	}
	return nil
}

func (windowsPlatform) ConfigureService(paths Paths, snapshot Snapshot) error {
	state, err := windowsState(snapshot)
	if err != nil {
		return err
	}
	manager, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	configuration := mgr.Config{}
	if !state.service.existed {
		configuration = mgr.Config{
			DisplayName:      "MirvMon Agent",
			Description:      "MirvMon outbound monitoring agent",
			StartType:        mgr.StartAutomatic,
			ServiceStartName: "LocalSystem",
		}
		service, err := manager.CreateService(serviceName, paths.InstalledAgent, configuration, "run", "--config", paths.InstalledConfig)
		if service != nil {
			defer service.Close()
		}
		return err
	}
	service, err := manager.OpenService(serviceName)
	if err != nil {
		return err
	}
	defer service.Close()
	configuration = state.service.config
	configuration.StartType = mgr.StartAutomatic
	configuration.ServiceStartName = "LocalSystem"
	configuration.Description = "MirvMon outbound monitoring agent"
	configuration.BinaryPathName = syscall.EscapeArg(paths.InstalledAgent) + " run --config " + syscall.EscapeArg(paths.InstalledConfig)
	return service.UpdateConfig(configuration)
}

func (windowsPlatform) StartService() error {
	manager, service, err := openService()
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	defer service.Close()
	return service.Start()
}

func (windowsPlatform) VerifyService() error {
	deadline := time.Now().Add(serviceWait)
	for time.Now().Before(deadline) {
		manager, service, err := openService()
		if err == nil {
			status, queryErr := service.Query()
			service.Close()
			manager.Disconnect()
			if queryErr == nil && status.State == svc.Running {
				return nil
			}
		}
		time.Sleep(time.Second)
	}
	return errors.New("service did not start")
}

func (windowsPlatform) DeleteLegacyTask(snapshot Snapshot) error {
	state, err := windowsState(snapshot)
	if err != nil || !state.task.existed {
		return err
	}
	return runSchtasks("/Delete", "/TN", legacyTask, "/F")
}

func (windowsPlatform) Rollback(paths Paths, snapshot Snapshot) error {
	state, err := windowsState(snapshot)
	if err != nil {
		return err
	}
	_ = stopService()
	for path, file := range state.files {
		if file.existed {
			if err := copyFile(file.backup, path, 0600); err != nil {
				return err
			}
		} else if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
			return err
		}
	}
	if err := restoreService(state.service); err != nil {
		return err
	}
	return restoreTask(state.task)
}

func currentWindowsVersion() (WindowsVersion, error) {
	info := rtlVersionInfo{Size: uint32(unsafe.Sizeof(rtlVersionInfo{}))}
	status, _, _ := rtlGetVersion.Call(uintptr(unsafe.Pointer(&info)))
	if status != 0 {
		return WindowsVersion{}, fmt.Errorf("RtlGetVersion status %d", status)
	}
	return WindowsVersion{Major: info.Major, Minor: info.Minor, ServicePack: info.ServicePackMajor}, nil
}

func protectPath(path, sddl string) error {
	descriptor, err := windows.SecurityDescriptorFromString(sddl)
	if err != nil {
		return err
	}
	dacl, _, err := descriptor.DACL()
	if err != nil {
		return err
	}
	return windows.SetNamedSecurityInfo(path, windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION,
		nil, nil, dacl, nil)
}

func snapshotFile(path, backup string) (bool, error) {
	if !regularFile(path) {
		return false, nil
	}
	return true, copyFile(path, backup, 0600)
}

func copyFile(source, destination string, mode os.FileMode) error {
	contents, err := os.ReadFile(source)
	if err != nil {
		return err
	}
	return atomicfile.Write(destination, contents, mode)
}

func snapshotService() (serviceSnapshot, error) {
	manager, err := mgr.Connect()
	if err != nil {
		return serviceSnapshot{}, err
	}
	defer manager.Disconnect()
	service, err := manager.OpenService(serviceName)
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return serviceSnapshot{}, nil
	}
	if err != nil {
		return serviceSnapshot{}, err
	}
	defer service.Close()
	configuration, err := service.Config()
	if err != nil {
		return serviceSnapshot{}, err
	}
	status, err := service.Query()
	if err != nil {
		return serviceSnapshot{}, err
	}
	return serviceSnapshot{existed: true, config: configuration, state: status.State}, nil
}

func openService() (*mgr.Mgr, *mgr.Service, error) {
	manager, err := mgr.Connect()
	if err != nil {
		return nil, nil, err
	}
	service, err := manager.OpenService(serviceName)
	if err != nil {
		manager.Disconnect()
		return nil, nil, err
	}
	return manager, service, nil
}

func stopService() error {
	manager, service, err := openService()
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return nil
	}
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	defer service.Close()
	status, err := service.Query()
	if err != nil || status.State == svc.Stopped {
		return err
	}
	if _, err := service.Control(svc.Stop); err != nil && !errors.Is(err, windows.ERROR_SERVICE_NOT_ACTIVE) {
		return err
	}
	deadline := time.Now().Add(serviceWait)
	for time.Now().Before(deadline) {
		status, err = service.Query()
		if err != nil {
			return err
		}
		if status.State == svc.Stopped {
			return nil
		}
		time.Sleep(time.Second)
	}
	return errors.New("service did not stop")
}

func restoreService(original serviceSnapshot) error {
	manager, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	service, err := manager.OpenService(serviceName)
	if !original.existed {
		if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return nil
		}
		if err != nil {
			return err
		}
		defer service.Close()
		return service.Delete()
	}
	if err != nil {
		return err
	}
	defer service.Close()
	if err := service.UpdateConfig(original.config); err != nil {
		return err
	}
	if original.state == svc.Running {
		return service.Start()
	}
	return nil
}

func windowsState(snapshot Snapshot) (*windowsSnapshot, error) {
	state, ok := snapshot.Value.(*windowsSnapshot)
	if !ok || state == nil {
		return nil, errors.New("invalid Windows snapshot")
	}
	return state, nil
}

func snapshotTask(definitionPath string) (taskSnapshot, error) {
	exists, err := taskExists()
	if err != nil || !exists {
		return taskSnapshot{}, err
	}
	state, err := queryTaskState()
	if err != nil {
		return taskSnapshot{}, err
	}
	state.existed = true
	definition, err := exec.Command("schtasks.exe", "/Query", "/TN", legacyTask, "/XML").Output()
	if err != nil {
		return taskSnapshot{}, err
	}
	if err := atomicfile.Write(definitionPath, definition, 0600); err != nil {
		return taskSnapshot{}, err
	}
	state.definitionPath = definitionPath
	return state, nil
}

func taskExists() (bool, error) {
	command := exec.Command("schtasks.exe", "/Query", "/TN", legacyTask)
	err := command.Run()
	if err == nil {
		return true, nil
	}
	var exit *exec.ExitError
	if errors.As(err, &exit) && exit.ExitCode() == 1 {
		return false, nil
	}
	return false, err
}

func queryTaskState() (taskSnapshot, error) {
	var result taskSnapshot
	err := withLegacyTask(func(task *ole.IDispatch) error {
		enabled, err := oleutil.GetProperty(task, "Enabled")
		if err != nil {
			return err
		}
		defer enabled.Clear()
		state, err := oleutil.GetProperty(task, "State")
		if err != nil {
			return err
		}
		defer state.Clear()
		result.enabled = enabled.Val != 0
		result.running = int(state.Val) == taskStateRun
		return nil
	})
	return result, err
}

func withLegacyTask(action func(*ole.IDispatch) error) error {
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()
	if err := ole.CoInitializeEx(0, ole.COINIT_APARTMENTTHREADED); err != nil {
		var oleError *ole.OleError
		if !errors.As(err, &oleError) || oleError.Code() != 1 {
			return err
		}
	}
	defer ole.CoUninitialize()
	unknown, err := oleutil.CreateObject("Schedule.Service")
	if err != nil {
		return err
	}
	defer unknown.Release()
	service, err := unknown.QueryInterface(ole.IID_IDispatch)
	if err != nil {
		return err
	}
	defer service.Release()
	connected, err := oleutil.CallMethod(service, "Connect")
	if err != nil {
		return err
	}
	connected.Clear()
	folderValue, err := oleutil.CallMethod(service, "GetFolder", `\`)
	if err != nil {
		return err
	}
	defer folderValue.Clear()
	folder := folderValue.ToIDispatch()
	taskValue, err := oleutil.CallMethod(folder, "GetTask", legacyTask)
	if err != nil {
		return err
	}
	defer taskValue.Clear()
	return action(taskValue.ToIDispatch())
}

func restoreTask(task taskSnapshot) error {
	if !task.existed {
		return nil
	}
	exists, err := taskExists()
	if err != nil {
		return err
	}
	if !exists {
		if err := runSchtasks("/Create", "/TN", legacyTask, "/XML", task.definitionPath, "/F"); err != nil {
			return err
		}
	}
	if task.enabled || task.running {
		if err := runSchtasks("/Change", "/TN", legacyTask, "/Enable"); err != nil {
			return err
		}
	}
	if task.running {
		if err := runSchtasks("/Run", "/TN", legacyTask); err != nil {
			return err
		}
	}
	if !task.enabled {
		return runSchtasks("/Change", "/TN", legacyTask, "/Disable")
	}
	return nil
}

func runSchtasks(arguments ...string) error {
	return exec.Command("schtasks.exe", arguments...).Run()
}

func runSchtasksAllowed(allowed []int, arguments ...string) error {
	err := runSchtasks(arguments...)
	if err == nil {
		return nil
	}
	var exit *exec.ExitError
	if errors.As(err, &exit) {
		for _, code := range allowed {
			if exit.ExitCode() == code {
				return nil
			}
		}
	}
	return err
}
