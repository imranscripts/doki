package model

import (
	"bytes"
	"encoding/json"
	"strings"
)

type StepTargetRef struct {
	Mode            string `json:"-"`
	TargetID        string `json:"targetId"`
	FromEnvironment string `json:"fromEnvironment"`
}

func (r *StepTargetRef) UnmarshalJSON(data []byte) error {
	if r == nil {
		return nil
	}

	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || bytes.Equal(trimmed, []byte("null")) {
		*r = StepTargetRef{}
		return nil
	}

	switch trimmed[0] {
	case '"':
		var raw string
		if err := json.Unmarshal(trimmed, &raw); err != nil {
			return err
		}
		raw = strings.TrimSpace(raw)
		switch strings.ToLower(raw) {
		case "", "inherit", "none":
			*r = StepTargetRef{Mode: "inherit"}
		default:
			*r = StepTargetRef{Mode: "targetId", TargetID: raw}
		}
		return nil
	case '{':
		var parsed struct {
			TargetID        string `json:"targetId"`
			FromEnvironment string `json:"fromEnvironment"`
		}
		if err := json.Unmarshal(trimmed, &parsed); err != nil {
			return err
		}
		parsed.TargetID = strings.TrimSpace(parsed.TargetID)
		parsed.FromEnvironment = strings.TrimSpace(parsed.FromEnvironment)
		result := StepTargetRef{
			TargetID:        parsed.TargetID,
			FromEnvironment: parsed.FromEnvironment,
		}
		switch {
		case parsed.TargetID != "":
			result.Mode = "targetId"
		case parsed.FromEnvironment != "":
			result.Mode = "fromEnvironment"
		default:
			result.Mode = "inherit"
		}
		*r = result
		return nil
	default:
		*r = StepTargetRef{}
		return nil
	}
}

func (r StepTargetRef) NormalizedMode() string {
	mode := strings.ToLower(strings.TrimSpace(r.Mode))
	if mode != "" {
		return mode
	}
	switch {
	case strings.TrimSpace(r.TargetID) != "":
		return "targetid"
	case strings.TrimSpace(r.FromEnvironment) != "":
		return "fromenvironment"
	default:
		return "inherit"
	}
}
