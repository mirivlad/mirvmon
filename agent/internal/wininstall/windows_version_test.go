package wininstall

import "testing"

func TestArtifactForWindowsVersion(t *testing.T) {
	tests := []struct {
		name    string
		version WindowsVersion
		want    string
		ok      bool
	}{
		{"windows 7 sp1", WindowsVersion{Major: 6, Minor: 1, ServicePack: 1}, "windows-legacy-amd64", true},
		{"windows 7 without sp1", WindowsVersion{Major: 6, Minor: 1}, "", false},
		{"windows 8", WindowsVersion{Major: 6, Minor: 2}, "windows-legacy-amd64", true},
		{"windows 8.1", WindowsVersion{Major: 6, Minor: 3}, "windows-legacy-amd64", true},
		{"windows 10", WindowsVersion{Major: 10}, "windows-amd64", true},
		{"future windows", WindowsVersion{Major: 11}, "windows-amd64", true},
		{"server 2008 without r2", WindowsVersion{Major: 6, Minor: 0, ServicePack: 2}, "", false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			artifact, ok := ArtifactForWindowsVersion(test.version)
			if artifact != test.want || ok != test.ok {
				t.Fatalf("artifact=%q ok=%v want=%q,%v", artifact, ok, test.want, test.ok)
			}
		})
	}
}
