package model

// ResolveTargetFromEnvironment follows the PHP orchestration resolution order:
// 1) If targetType is provided, find a target whose actual type matches.
// 2) If environment.targets has "default", use that.
// 3) Otherwise, return the first target in the map.
func ResolveTargetFromEnvironment(env Environment, targetType string, targets []Target) string {
	if len(env.Targets) == 0 {
		return ""
	}

	if targetType != "" {
		for _, targetID := range env.Targets {
			if targetMatchesType(targetID, targetType, targets) {
				return targetID
			}
		}
	}

	if def, ok := env.Targets["default"]; ok {
		return def
	}

	for _, targetID := range env.Targets {
		return targetID
	}

	return ""
}

func targetMatchesType(targetID string, targetType string, targets []Target) bool {
	for _, target := range targets {
		if target.ID == targetID {
			return target.Type == targetType
		}
	}
	return false
}
