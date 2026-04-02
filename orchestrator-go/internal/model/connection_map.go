package model

import (
	"bytes"
	"encoding/json"
)

type ConnectionMap map[string]interface{}

func (cm *ConnectionMap) UnmarshalJSON(data []byte) error {
	if cm == nil {
		return nil
	}

	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) {
		*cm = ConnectionMap{}
		return nil
	}

	switch trimmed[0] {
	case '{':
		var raw map[string]interface{}
		if err := json.Unmarshal(trimmed, &raw); err != nil {
			return err
		}
		*cm = raw
		return nil
	case '[':
		*cm = ConnectionMap{}
		return nil
	default:
		*cm = ConnectionMap{}
		return nil
	}
}
