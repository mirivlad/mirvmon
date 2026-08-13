//go:build !windows

package wininstall

import "errors"

var errUnsupportedPlatform = errors.New("Windows installation is unavailable")

// NewPlatform returns a rejecting adapter outside Windows.
func NewPlatform() Platform { return unsupportedPlatform{} }

// DefaultDirectories are unavailable outside Windows.
func DefaultDirectories() (string, string, error) { return "", "", errUnsupportedPlatform }

type unsupportedPlatform struct{}

func (unsupportedPlatform) Validate(Request) error   { return errUnsupportedPlatform }
func (unsupportedPlatform) ProtectStage(Paths) error { return errUnsupportedPlatform }
func (unsupportedPlatform) Snapshot(Paths) (Snapshot, error) {
	return Snapshot{}, errUnsupportedPlatform
}
func (unsupportedPlatform) Protect(Paths) error                    { return errUnsupportedPlatform }
func (unsupportedPlatform) Freeze(Snapshot) error                  { return errUnsupportedPlatform }
func (unsupportedPlatform) InstallFiles(Paths, Snapshot) error     { return errUnsupportedPlatform }
func (unsupportedPlatform) ConfigureService(Paths, Snapshot) error { return errUnsupportedPlatform }
func (unsupportedPlatform) StartService() error                    { return errUnsupportedPlatform }
func (unsupportedPlatform) VerifyService() error                   { return errUnsupportedPlatform }
func (unsupportedPlatform) DeleteLegacyTask(Snapshot) error        { return errUnsupportedPlatform }
func (unsupportedPlatform) Rollback(Paths, Snapshot) error         { return errUnsupportedPlatform }
