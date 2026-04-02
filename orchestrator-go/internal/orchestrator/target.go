package orchestrator

import (
	"errors"
	"fmt"
	"strings"

	"doki/orchestrator/internal/model"
)

func AssertTargetVerified(target *model.Target) error {
	if target == nil {
		return nil
	}

	required := requiredCapabilityForTarget(target.Type)
	if required == "" {
		return nil
	}

	for _, cap := range target.VerifiedCapabilities {
		if cap == required {
			return nil
		}
	}

	return fmt.Errorf("target '%s' is not verified for %s", target.ID, required)
}

func requiredCapabilityForTarget(targetType string) string {
	switch targetType {
	case "docker", "local", "docker-exec":
		return "exec.docker"
	case "ssh":
		return "exec.ssh"
	case "http":
		return "http.call"
	case "kubernetes":
		return "kubernetes.exec"
	default:
		return ""
	}
}

func ResolveTargetBindings(template *model.Template, target *model.Target) map[string]string {
	resolved := map[string]string{}
	if template == nil || target == nil {
		return resolved
	}

	if len(template.TargetBindings) == 0 {
		return resolved
	}

	root := map[string]interface{}{
		"id":                   target.ID,
		"name":                 target.Name,
		"type":                 target.Type,
		"connection":           target.Connection,
		"verifiedCapabilities": target.VerifiedCapabilities,
	}

	for envName, binding := range template.TargetBindings {
		if binding.From == "" {
			continue
		}
		value, ok := resolveBindingPath(root, binding.From)
		if ok && value != "" {
			resolved[envName] = value
		}
	}

	return resolved
}

func resolveBindingPath(root map[string]interface{}, path string) (string, bool) {
	parts := strings.Split(path, ".")
	var current interface{} = root

	for _, part := range parts {
		if part == "" || part == "target" {
			continue
		}

		switch node := current.(type) {
		case map[string]interface{}:
			value, ok := node[part]
			if !ok {
				return "", false
			}
			current = value
		case map[string]string:
			value, ok := node[part]
			if !ok {
				return "", false
			}
			current = value
		default:
			return "", false
		}
	}

	if current == nil {
		return "", false
	}

	switch v := current.(type) {
	case string:
		return v, true
	case fmt.Stringer:
		return v.String(), true
	case int, int64, float64, float32, bool:
		return fmt.Sprint(v), true
	default:
		return "", false
	}
}

func TargetByID(targets map[string]*model.Target, id string) (*model.Target, error) {
	if id == "" {
		return nil, nil
	}
	target := targets[id]
	if target == nil {
		return nil, errors.New("target not found")
	}
	return target, nil
}
