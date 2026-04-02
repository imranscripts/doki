package model

import (
	"bytes"
	"encoding/json"
)

type StringMap map[string]string

func (sm *StringMap) UnmarshalJSON(data []byte) error {
	if sm == nil {
		return nil
	}

	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) {
		*sm = StringMap{}
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
		*sm = StringMap{}
		return nil
	default:
		*sm = StringMap{}
		return nil
	}
}
