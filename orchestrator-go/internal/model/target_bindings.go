package model

import (
	"bytes"
	"encoding/json"
)

type TargetBindings map[string]TargetBinding

type targetBindingEntry struct {
	Name string `json:"name"`
	From string `json:"from"`
}

func (tb *TargetBindings) UnmarshalJSON(data []byte) error {
	if tb == nil {
		return nil
	}

	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) {
		*tb = TargetBindings{}
		return nil
	}

	switch trimmed[0] {
	case '{':
		var raw map[string]TargetBinding
		if err := json.Unmarshal(trimmed, &raw); err != nil {
			return err
		}
		*tb = raw
		return nil
	case '[':
		var entries []targetBindingEntry
		if err := json.Unmarshal(trimmed, &entries); err != nil {
			*tb = TargetBindings{}
			return nil
		}
		result := TargetBindings{}
		for _, entry := range entries {
			if entry.Name == "" {
				continue
			}
			result[entry.Name] = TargetBinding{From: entry.From}
		}
		*tb = result
		return nil
	default:
		*tb = TargetBindings{}
		return nil
	}
}
