package model

import (
	"bytes"
	"encoding/json"
)

type SecretMap map[string]string

type secretEntry struct {
	Name     string `json:"name"`
	Env      string `json:"env"`
	SecretID string `json:"secretId"`
}

func (sm *SecretMap) UnmarshalJSON(data []byte) error {
	if sm == nil {
		return nil
	}

	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) {
		*sm = SecretMap{}
		return nil
	}

	switch trimmed[0] {
	case '{':
		var raw map[string]string
		if err := json.Unmarshal(trimmed, &raw); err != nil {
			return err
		}
		*sm = raw
		return nil
	case '[':
		// Could be empty list or list of entries
		if bytes.Equal(trimmed, []byte("[]")) {
			*sm = SecretMap{}
			return nil
		}
		var entries []secretEntry
		if err := json.Unmarshal(trimmed, &entries); err == nil {
			result := SecretMap{}
			for _, entry := range entries {
				key := entry.Env
				if key == "" {
					key = entry.Name
				}
				if key == "" || entry.SecretID == "" {
					continue
				}
				result[key] = entry.SecretID
			}
			*sm = result
			return nil
		}

		// Fallback: try list of strings (ignore)
		var list []string
		if err := json.Unmarshal(trimmed, &list); err == nil {
			*sm = SecretMap{}
			return nil
		}

		*sm = SecretMap{}
		return nil
	default:
		*sm = SecretMap{}
		return nil
	}
}
