package model

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strconv"
)

func ValidateInputs(template *Template, values map[string]interface{}) []string {
	if template == nil {
		return []string{"Template not found"}
	}

	var errors []string

	for _, input := range template.Inputs {
		name := input.Name
		value, ok := values[name]
		if !ok {
			value = nil
		}

		if input.Required && isMissing(value) {
			label := inputLabel(input)
			errors = append(errors, fmt.Sprintf("'%s' is required", label))
			continue
		}

		if isMissing(value) {
			continue
		}

		typeName := input.Type
		if typeName == "" {
			typeName = "string"
		}

		if typeName == "number" && !isNumeric(value) {
			label := inputLabel(input)
			errors = append(errors, fmt.Sprintf("'%s' must be a number", label))
			continue
		}

		if input.Validation != nil {
			label := inputLabel(input)
			valRules := input.Validation
			if valRules.Min != nil {
				if num, ok := toFloat(value); ok && num < *valRules.Min {
					errors = append(errors, fmt.Sprintf("'%s' must be at least %v", label, trimFloat(*valRules.Min)))
				}
			}
			if valRules.Max != nil {
				if num, ok := toFloat(value); ok && num > *valRules.Max {
					errors = append(errors, fmt.Sprintf("'%s' must be at most %v", label, trimFloat(*valRules.Max)))
				}
			}
			if valRules.MaxLength != nil {
				valueString := fmt.Sprint(value)
				if len(valueString) > *valRules.MaxLength {
					errors = append(errors, fmt.Sprintf("'%s' exceeds maximum length of %d", label, *valRules.MaxLength))
				}
			}
			if valRules.Pattern != "" {
				pattern := valRules.Pattern
				compiled, err := regexp.Compile(pattern)
				if err != nil || !compiled.MatchString(fmt.Sprint(value)) {
					errors = append(errors, fmt.Sprintf("'%s' format is invalid", label))
				}
			}
		}
	}

	return errors
}

func inputLabel(input TemplateInput) string {
	if input.Label != "" {
		return input.Label
	}
	return input.Name
}

func isMissing(value interface{}) bool {
	if value == nil {
		return true
	}
	if s, ok := value.(string); ok {
		return s == ""
	}
	return false
}

func isNumeric(value interface{}) bool {
	_, ok := toFloat(value)
	return ok
}

func toFloat(value interface{}) (float64, bool) {
	if value == nil {
		return 0, false
	}
	switch v := value.(type) {
	case int:
		return float64(v), true
	case int64:
		return float64(v), true
	case float64:
		return v, true
	case float32:
		return float64(v), true
	case json.Number:
		if f, err := v.Float64(); err == nil {
			return f, true
		}
	case string:
		if v == "" {
			return 0, false
		}
		if f, err := strconv.ParseFloat(v, 64); err == nil {
			return f, true
		}
	}
	return 0, false
}

func trimFloat(value float64) string {
	if value == float64(int64(value)) {
		return strconv.FormatInt(int64(value), 10)
	}
	return strconv.FormatFloat(value, 'f', -1, 64)
}
