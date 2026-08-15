package update

import "testing"

func TestIsUpgradeSupportsFourPartHotfixVersions(t *testing.T) {
	tests := []struct {
		name      string
		installed string
		target    string
		want      bool
	}{
		{name: "hotfix upgrade", installed: "v0.4.15.1", target: "v0.4.15.2", want: true},
		{name: "patch to hotfix", installed: "v0.4.15", target: "v0.4.15.1", want: true},
		{name: "hotfix to next patch", installed: "v0.4.15.9", target: "v0.4.16", want: true},
		{name: "hotfix downgrade", installed: "v0.4.15.2", target: "v0.4.15.1", want: false},
		{name: "equal hotfix", installed: "v0.4.15.2", target: "v0.4.15.2", want: false},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := isUpgrade(test.installed, test.target); got != test.want {
				t.Fatalf("isUpgrade(%q, %q)=%v want=%v", test.installed, test.target, got, test.want)
			}
		})
	}
}
