package wininstall

// WindowsVersion contains the kernel and service-pack values used by the
// supported-platform contract.
type WindowsVersion struct {
	Major       uint32
	Minor       uint32
	ServicePack uint16
}

// ArtifactForWindowsVersion selects the one compatible native build.
func ArtifactForWindowsVersion(version WindowsVersion) (string, bool) {
	if version.Major == 6 && version.Minor == 1 {
		if version.ServicePack < 1 {
			return "", false
		}
		return "windows-legacy-amd64", true
	}
	if version.Major == 6 && (version.Minor == 2 || version.Minor == 3) {
		return "windows-legacy-amd64", true
	}
	if version.Major >= 10 {
		return "windows-amd64", true
	}
	return "", false
}
