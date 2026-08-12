//go:build windows

package collector

import (
	"strconv"
	"strings"

	"golang.org/x/sys/windows/registry"
)

func registryOperatingSystem() (windowsOperatingSystem, error) {
	key, err := registry.OpenKey(
		registry.LOCAL_MACHINE,
		`SOFTWARE\Microsoft\Windows NT\CurrentVersion`,
		registry.QUERY_VALUE,
	)
	if err != nil {
		return windowsOperatingSystem{}, err
	}
	defer key.Close()
	product, _, err := key.GetStringValue("ProductName")
	if err != nil {
		return windowsOperatingSystem{}, err
	}
	build, _, err := key.GetStringValue("CurrentBuildNumber")
	if err != nil {
		return windowsOperatingSystem{}, err
	}
	servicePack, _, err := key.GetStringValue("CSDVersion")
	if err != nil && err != registry.ErrNotExist {
		return windowsOperatingSystem{}, err
	}
	major := uint16(0)
	servicePack = strings.TrimSpace(servicePack)
	if strings.HasPrefix(servicePack, "Service Pack ") {
		if number, conversionErr := strconv.ParseUint(strings.TrimPrefix(servicePack, "Service Pack "), 10, 16); conversionErr == nil {
			major = uint16(number)
		}
	}
	return windowsOperatingSystem{
		Caption: product, BuildNumber: build, ServicePackMajorVersion: major,
	}, nil
}
