package collector

import "testing"

func TestNormalizeWindowsVersion(t *testing.T) {
	tests := []struct {
		product, servicePack, build, want string
	}{
		{"Windows 7 Professional", "Service Pack 1", "7601", "Windows 7 Professional SP1 (build 7601)"},
		{"Windows Server 2008 R2 Enterprise", "Service Pack 1", "7601", "Windows Server 2008 R2 Enterprise SP1 (build 7601)"},
		{"Windows Server 2025 Standard", "", "26100", "Windows Server 2025 Standard (build 26100)"},
	}
	for _, test := range tests {
		if got := normalizeWindowsVersion(test.product, test.servicePack, test.build); got != test.want {
			t.Errorf("got %q want %q", got, test.want)
		}
	}
}
