package model

import (
	"bytes"
	"encoding/json"
	"strings"
)

type EnvironmentMap map[string]Environment

type environmentEntry struct {
	ID      string    `json:"id"`
	Name    string    `json:"name"`
	Targets StringMap `json:"targets"`
}

func (em *EnvironmentMap) UnmarshalJSON(data []byte) error {
	if em == nil {
		return nil
	}

	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) {
		*em = EnvironmentMap{}
		return nil
	}

	switch trimmed[0] {
	case '{':
		var raw map[string]Environment
		if err := json.Unmarshal(trimmed, &raw); err != nil {
			return err
		}
		*em = raw
		return nil
	case '[':
		if bytes.Equal(trimmed, []byte("[]")) {
			*em = EnvironmentMap{}
			return nil
		}

		var entries []environmentEntry
		if err := json.Unmarshal(trimmed, &entries); err != nil {
			*em = EnvironmentMap{}
			return nil
		}

		result := EnvironmentMap{}
		for _, entry := range entries {
			key := strings.TrimSpace(entry.ID)
			if key == "" {
				key = strings.TrimSpace(entry.Name)
			}
			if key == "" {
				continue
			}

			result[key] = Environment{
				Name:    entry.Name,
				Targets: entry.Targets,
			}
		}

		*em = result
		return nil
	default:
		*em = EnvironmentMap{}
		return nil
	}
}
