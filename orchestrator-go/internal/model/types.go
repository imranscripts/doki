package model

import "encoding/json"

type Bundle struct {
	Templates    []Template     `json:"templates"`
	Commands     []Command      `json:"commands"`
	Targets      []Target       `json:"targets"`
	Environments EnvironmentMap `json:"environments"`
	GeneratedAt  string         `json:"generatedAt"`
}

type Template struct {
	ID             string                 `json:"id"`
	Name           string                 `json:"name"`
	Version        string                 `json:"version"`
	Description    string                 `json:"description"`
	Icon           string                 `json:"icon"`
	Color          string                 `json:"color"`
	Category       string                 `json:"category"`
	Path           string                 `json:"path"`
	TargetType     *string                `json:"targetType"`
	Runtime        RuntimeConfig          `json:"runtime"`
	Inputs         []TemplateInput        `json:"inputs"`
	Execution      ExecutionConfig        `json:"execution"`
	Output         map[string]interface{} `json:"output"`
	Requirements   []string               `json:"requirements"`
	TargetBindings TargetBindings         `json:"targetBindings"`
}

type RuntimeConfig struct {
	Image     string                 `json:"image"`
	Workdir   string                 `json:"workdir"`
	Network   string                 `json:"network"`
	Volumes   []RuntimeVolume        `json:"volumes"`
	Resources map[string]interface{} `json:"resources"`
}

type RuntimeVolume struct {
	Host      string `json:"host"`
	Container string `json:"container"`
	Readonly  bool   `json:"readonly"`
}

type ExecutionConfig struct {
	Mode    string `json:"mode"`
	Script  string `json:"script"`
	Command string `json:"command"`
	Timeout int    `json:"timeout"`
}

type TemplateInput struct {
	Name        string           `json:"name"`
	Label       string           `json:"label"`
	Type        string           `json:"type"`
	Required    bool             `json:"required"`
	Default     interface{}      `json:"default"`
	Validation  *ValidationRules `json:"validation"`
	Options     []InputOption    `json:"options"`
	Placeholder string           `json:"placeholder"`
	Description string           `json:"description"`
}

type InputOption struct {
	Value string `json:"value"`
	Label string `json:"label"`
}

type ValidationRules struct {
	Min       *float64 `json:"min"`
	Max       *float64 `json:"max"`
	MaxLength *int     `json:"maxLength"`
	Pattern   string   `json:"pattern"`
}

type TargetBinding struct {
	From string `json:"from"`
}

type Command struct {
	ID                string                 `json:"id"`
	Name              string                 `json:"name"`
	Description       string                 `json:"description"`
	ExecutionMode     string                 `json:"executionMode"`
	MaxParallel       int                    `json:"maxParallel"`
	FinalStatusPolicy string                 `json:"finalStatusPolicy"`
	DefaultTarget     StepTargetRef          `json:"defaultTarget"`
	Steps             []CommandStep          `json:"steps"`
	TemplateID        string                 `json:"templateId"`
	TargetID          string                 `json:"targetId"`
	Environment       string                 `json:"environment"`
	Inputs            map[string]interface{} `json:"inputs"`
	Secrets           SecretMap              `json:"secrets"`
	Tags              []string               `json:"tags"`
	Icon              *string                `json:"icon"`
	Color             *string                `json:"color"`
	Enabled           bool                   `json:"enabled"`
	CreatedAt         *string                `json:"createdAt"`
	CreatedBy         *string                `json:"createdBy"`
	UpdatedAt         *string                `json:"updatedAt"`
	UpdatedBy         *string                `json:"updatedBy"`
}

type CommandStep struct {
	ID         string                 `json:"id"`
	TemplateID string                 `json:"templateId"`
	Target     StepTargetRef          `json:"target"`
	DependsOn  []string               `json:"dependsOn"`
	OnFailure  string                 `json:"onFailure"`
	Inputs     map[string]interface{} `json:"inputs"`
	Secrets    SecretMap              `json:"secrets"`
}

type Target struct {
	ID                   string        `json:"id"`
	Name                 string        `json:"name"`
	Type                 string        `json:"type"`
	Connection           ConnectionMap `json:"connection"`
	VerifiedCapabilities []string      `json:"verifiedCapabilities"`
}

type Environment struct {
	Name    string    `json:"name"`
	Targets StringMap `json:"targets"`
}

type ConfigResponse struct {
	Success bool   `json:"success"`
	Error   string `json:"error"`
	Bundle  Bundle `json:"bundle"`
}

type SecretsResponse struct {
	Success bool              `json:"success"`
	Error   string            `json:"error"`
	Locked  bool              `json:"locked"`
	Secrets map[string]string `json:"secrets"`
}

type EventsResponse struct {
	Success   bool     `json:"success"`
	Processed int      `json:"processed"`
	Errors    []string `json:"errors"`
}

type ExecuteRequest struct {
	CommandID    string                 `json:"commandId"`
	CommandDraft *Command               `json:"commandDraft,omitempty"`
	TemplateID   string                 `json:"templateId"`
	Inputs       map[string]interface{} `json:"inputs"`
	TargetID     string                 `json:"targetId"`
	DryRun       bool                   `json:"dryRun"`
	User         map[string]interface{} `json:"user"`
	Request      map[string]interface{} `json:"request"`
}

type ExecuteResponse struct {
	Success  bool     `json:"success"`
	JobID    string   `json:"jobId,omitempty"`
	Status   string   `json:"status,omitempty"`
	Output   string   `json:"output,omitempty"`
	ExitCode int      `json:"exitCode,omitempty"`
	DryRun   bool     `json:"dryRun,omitempty"`
	Error    string   `json:"error,omitempty"`
	Errors   []string `json:"errors,omitempty"`
}

type TokenInfo struct {
	Raw json.RawMessage
}
