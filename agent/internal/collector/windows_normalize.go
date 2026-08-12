package collector

import "strings"

func normalizeWindowsVersion(product, servicePack, build string) string {
	product = strings.TrimSpace(product)
	servicePack = strings.TrimSpace(servicePack)
	build = strings.TrimSpace(build)
	if strings.HasPrefix(servicePack, "Service Pack ") {
		servicePack = "SP" + strings.TrimSpace(strings.TrimPrefix(servicePack, "Service Pack "))
	}
	parts := []string{product}
	if servicePack != "" {
		parts = append(parts, servicePack)
	}
	result := strings.TrimSpace(strings.Join(parts, " "))
	if build != "" {
		result += " (build " + build + ")"
	}
	return truncateOSVersion(result)
}
