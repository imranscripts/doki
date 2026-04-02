package model

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
	"strings"
)

var ifBlockRegex = regexp.MustCompile(`(?s)\{\{#if ([a-zA-Z0-9_.-]+)\}\}(.*?)\{\{\/if\}\}`)
var placeholderRegex = regexp.MustCompile(`\{\{([a-zA-Z0-9_.-]+)\}\}`)

func InterpolateScript(script string, values map[string]interface{}) string {
	result := script

	result = ifBlockRegex.ReplaceAllStringFunc(result, func(match string) string {
		parts := ifBlockRegex.FindStringSubmatch(match)
		if len(parts) != 3 {
			return ""
		}
		condition := parts[1]
		content := parts[2]
		value, ok := LookupPath(values, condition)
		if !ok || !isTruthy(value) {
			return ""
		}
		return content
	})

	result = placeholderRegex.ReplaceAllStringFunc(result, func(match string) string {
		parts := placeholderRegex.FindStringSubmatch(match)
		if len(parts) != 2 {
			return match
		}
		value, ok := LookupPath(values, parts[1])
		if !ok || value == nil {
			return match
		}
		if isScalar(value) {
			return scalarToString(value)
		}
		raw, err := json.Marshal(value)
		if err != nil {
			return fmt.Sprint(value)
		}
		return string(raw)
	})

	return result
}

func LookupPath(values map[string]interface{}, path string) (interface{}, bool) {
	path = strings.TrimSpace(path)
	if path == "" {
		return nil, false
	}
	segments := strings.Split(path, ".")
	var current interface{} = values
	for _, segment := range segments {
		segment = strings.TrimSpace(segment)
		if segment == "" {
			return nil, false
		}
		switch typed := current.(type) {
		case map[string]interface{}:
			next, ok := typed[segment]
			if !ok {
				return nil, false
			}
			current = next
		case []interface{}:
			idx, err := strconv.Atoi(segment)
			if err != nil || idx < 0 || idx >= len(typed) {
				return nil, false
			}
			current = typed[idx]
		default:
			return nil, false
		}
	}
	return current, true
}

func isScalar(value interface{}) bool {
	switch value.(type) {
	case string, bool, int, int64, float64, float32, json.Number:
		return true
	default:
		return false
	}
}

func scalarToString(value interface{}) string {
	switch v := value.(type) {
	case bool:
		if v {
			return "1"
		}
		return ""
	default:
		return fmt.Sprint(v)
	}
}

func isTruthy(value interface{}) bool {
	if value == nil {
		return false
	}
	if s, ok := value.(string); ok {
		if s == "" || s == "false" {
			return false
		}
		return true
	}
	if b, ok := value.(bool); ok {
		return b
	}
	return true
}
