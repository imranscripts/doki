/**
 * ============================================================================
 * DOKI ORCHESTRATOR (Conceptual Implementation)
 * ============================================================================
 * 
 * This is a TypeScript pseudocode showing how the template orchestrator works.
 * The actual implementation could be in Go, Node.js, or even PHP with shell exec.
 * 
 * Key responsibilities:
 * 1. Load and validate templates
 * 2. Create commands from templates
 * 3. Execute commands in isolated containers
 * 4. Stream output back to the UI
 * 5. Collect and store artifacts
 * ============================================================================
 */

import Docker from 'dockerode';
import yaml from 'yaml';
import Ajv from 'ajv';
import { EventEmitter } from 'events';

// ============================================================================
// TYPES
// ============================================================================

interface Template {
  id: string;
  name: string;
  category: string;
  runtime: {
    image: string;
    workdir?: string;
    env?: Array<{ name: string; value?: string; fromSecret?: string }>;
    volumes?: Array<{ host: string; container: string; readonly: boolean }>;
    resources?: { memory?: string; cpu?: string; timeout?: number };
    network?: string;
  };
  inputs: Array<{
    name: string;
    label: string;
    type: 'string' | 'number' | 'boolean' | 'select' | 'multiselect';
    required: boolean;
    default?: any;
    validation?: Record<string, any>;
    options?: Array<{ value: string; label: string }>;
  }>;
  execution: {
    mode: 'sync' | 'async' | 'streaming';
    script: string;
    pollInterval?: number;
  };
  output: {
    type: 'text' | 'json' | 'report';
    extractors?: Array<{ name: string; pattern: string; display: string }>;
    artifacts?: Array<{ name: string; path: string; type: string; retention: string }>;
  };
  access: {
    allowedRoles: string[];
  };
}

interface Command {
  id: string;
  template: string;
  name: string;
  defaults?: Record<string, any>;
  access?: { allowedRoles: string[] };
}

interface ExecutionResult {
  jobId: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  output: string;
  exitCode?: number;
  artifacts?: string[];
  startedAt: Date;
  completedAt?: Date;
}

// ============================================================================
// TEMPLATE LOADER
// ============================================================================

class TemplateLoader {
  private templates: Map<string, Template> = new Map();
  private validator: Ajv;
  
  constructor() {
    this.validator = new Ajv();
    // Load template schema for validation
  }
  
  async loadTemplatesFromDirectory(dir: string): Promise<void> {
    // Scan directory for *.template.yaml files
    // Parse and validate each template
    // Store in map by ID
  }
  
  getTemplate(id: string): Template | undefined {
    return this.templates.get(id);
  }
  
  validateInputs(template: Template, inputs: Record<string, any>): string[] {
    const errors: string[] = [];
    
    for (const inputDef of template.inputs) {
      const value = inputs[inputDef.name];
      
      // Check required
      if (inputDef.required && (value === undefined || value === '')) {
        errors.push(`${inputDef.label} is required`);
        continue;
      }
      
      // Type validation
      if (value !== undefined) {
        if (inputDef.type === 'number' && typeof value !== 'number') {
          errors.push(`${inputDef.label} must be a number`);
        }
        
        // Pattern validation
        if (inputDef.validation?.pattern) {
          const regex = new RegExp(inputDef.validation.pattern);
          if (!regex.test(String(value))) {
            errors.push(`${inputDef.label} format is invalid`);
          }
        }
        
        // Min/Max for numbers
        if (inputDef.type === 'number') {
          if (inputDef.validation?.min !== undefined && value < inputDef.validation.min) {
            errors.push(`${inputDef.label} must be at least ${inputDef.validation.min}`);
          }
          if (inputDef.validation?.max !== undefined && value > inputDef.validation.max) {
            errors.push(`${inputDef.label} must be at most ${inputDef.validation.max}`);
          }
        }
      }
    }
    
    return errors;
  }
}

// ============================================================================
// EXECUTION ENGINE
// ============================================================================

class ExecutionEngine extends EventEmitter {
  private docker: Docker;
  private jobs: Map<string, ExecutionResult> = new Map();
  
  constructor() {
    super();
    this.docker = new Docker();
  }
  
  /**
   * Execute a command using its template in an isolated container
   */
  async execute(
    template: Template,
    command: Command,
    inputs: Record<string, any>,
    user: { username: string; roles: string[] }
  ): Promise<string> {
    // Generate job ID
    const jobId = `job-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    
    // Merge defaults with user inputs
    const mergedInputs = { ...command.defaults, ...inputs };
    
    // Initialize job tracking
    const job: ExecutionResult = {
      jobId,
      status: 'pending',
      output: '',
      startedAt: new Date(),
    };
    this.jobs.set(jobId, job);
    
    // Build the script with interpolated values
    const script = this.interpolateScript(template.execution.script, mergedInputs);
    
    // Prepare container configuration
    const containerConfig = this.buildContainerConfig(template, script, jobId);
    
    // Execute based on mode
    if (template.execution.mode === 'sync') {
      await this.executeSync(jobId, containerConfig, template);
    } else if (template.execution.mode === 'async' || template.execution.mode === 'streaming') {
      this.executeAsync(jobId, containerConfig, template);
    }
    
    return jobId;
  }
  
  /**
   * Build Docker container configuration from template
   */
  private buildContainerConfig(template: Template, script: string, jobId: string) {
    const config: Docker.ContainerCreateOptions = {
      Image: template.runtime.image,
      Cmd: ['/bin/sh', '-c', script],
      WorkingDir: template.runtime.workdir || '/workspace',
      Env: this.buildEnvVars(template.runtime.env || []),
      HostConfig: {
        AutoRemove: true,
        Memory: this.parseMemory(template.runtime.resources?.memory),
        CpuQuota: this.parseCpu(template.runtime.resources?.cpu),
        Binds: this.buildVolumeMounts(template.runtime.volumes || []),
        NetworkMode: template.runtime.network || 'bridge',
      },
      Labels: {
        'doki.job.id': jobId,
        'doki.template': template.id,
      },
    };
    
    return config;
  }
  
  /**
   * Interpolate template variables in script
   * Handles: {{variable}}, {{#if condition}}...{{/if}}
   */
  private interpolateScript(script: string, inputs: Record<string, any>): string {
    let result = script;
    
    // Simple variable replacement: {{varName}}
    for (const [key, value] of Object.entries(inputs)) {
      const regex = new RegExp(`\\{\\{${key}\\}\\}`, 'g');
      result = result.replace(regex, this.escapeShell(String(value ?? '')));
    }
    
    // Conditional blocks: {{#if condition}}...{{/if}}
    const ifRegex = /\{\{#if (\w+)\}\}([\s\S]*?)\{\{\/if\}\}/g;
    result = result.replace(ifRegex, (_, condition, content) => {
      const value = inputs[condition];
      return value && value !== 'false' ? content : '';
    });
    
    return result;
  }
  
  /**
   * Escape shell special characters for safety
   */
  private escapeShell(str: string): string {
    return str.replace(/(["\s'$`\\])/g, '\\$1');
  }
  
  /**
   * Build environment variables, resolving secrets
   */
  private buildEnvVars(envDefs: Template['runtime']['env']): string[] {
    return (envDefs || []).map(env => {
      let value = env.value || '';
      
      // Resolve from secret store
      if (env.fromSecret) {
        value = this.getSecret(env.fromSecret);
      }
      
      // Expand ${VAR} references
      value = value.replace(/\$\{(\w+)\}/g, (_, varName) => {
        return process.env[varName] || this.getSecret(varName) || '';
      });
      
      return `${env.name}=${value}`;
    });
  }
  
  /**
   * Get secret from secure storage (placeholder)
   */
  private getSecret(name: string): string {
    // In real implementation: fetch from Vault, AWS Secrets Manager, etc.
    return process.env[name] || '';
  }
  
  /**
   * Synchronous execution - wait for completion
   */
  private async executeSync(
    jobId: string,
    config: Docker.ContainerCreateOptions,
    template: Template
  ): Promise<void> {
    const job = this.jobs.get(jobId)!;
    job.status = 'running';
    
    try {
      const container = await this.docker.createContainer(config);
      await container.start();
      
      // Attach to output stream
      const stream = await container.logs({
        follow: true,
        stdout: true,
        stderr: true,
      });
      
      stream.on('data', (chunk: Buffer) => {
        job.output += chunk.toString();
        this.emit('output', jobId, chunk.toString());
      });
      
      // Wait for completion
      const { StatusCode } = await container.wait();
      job.exitCode = StatusCode;
      job.status = StatusCode === 0 ? 'completed' : 'failed';
      job.completedAt = new Date();
      
      // Collect artifacts
      await this.collectArtifacts(container, template, jobId);
      
    } catch (error) {
      job.status = 'failed';
      job.output += `\nError: ${error.message}`;
      job.completedAt = new Date();
    }
    
    this.emit('complete', jobId, job);
  }
  
  /**
   * Async execution - returns immediately, polls for status
   */
  private async executeAsync(
    jobId: string,
    config: Docker.ContainerCreateOptions,
    template: Template
  ): Promise<void> {
    // Same as sync but in background
    // Client polls /api/jobs/:jobId/status
    setImmediate(() => this.executeSync(jobId, config, template));
  }
  
  /**
   * Collect artifacts from container before it's removed
   */
  private async collectArtifacts(
    container: Docker.Container,
    template: Template,
    jobId: string
  ): Promise<string[]> {
    const artifactPaths: string[] = [];
    
    for (const artifact of template.output.artifacts || []) {
      try {
        // Copy file from container to host artifact storage
        const stream = await container.getArchive({ path: artifact.path });
        const destPath = `/artifacts/${jobId}/${artifact.name}`;
        // Extract and save...
        artifactPaths.push(destPath);
      } catch (e) {
        // Artifact not found, skip
      }
    }
    
    return artifactPaths;
  }
  
  /**
   * Get job status
   */
  getJob(jobId: string): ExecutionResult | undefined {
    return this.jobs.get(jobId);
  }
  
  // Helpers
  private parseMemory(mem?: string): number | undefined {
    if (!mem) return undefined;
    const match = mem.match(/^(\d+)([mg])$/i);
    if (!match) return undefined;
    const [, value, unit] = match;
    return parseInt(value) * (unit.toLowerCase() === 'g' ? 1024 * 1024 * 1024 : 1024 * 1024);
  }
  
  private parseCpu(cpu?: string): number | undefined {
    if (!cpu) return undefined;
    return Math.floor(parseFloat(cpu) * 100000);
  }
  
  private buildVolumeMounts(volumes: Template['runtime']['volumes']): string[] {
    return (volumes || []).map(v => {
      const mode = v.readonly ? 'ro' : 'rw';
      return `${v.host}:${v.container}:${mode}`;
    });
  }
}

// ============================================================================
// API ENDPOINTS (Express-style pseudocode)
// ============================================================================

/*
// List available templates
GET /api/templates
→ Returns all loaded templates (filtered by user role)

// Get template details
GET /api/templates/:id
→ Returns template with input definitions

// List commands
GET /api/commands
→ Returns commands available to user

// Execute command
POST /api/commands/:id/execute
Body: { inputs: { ... } }
→ Returns { jobId: "..." }

// Get job status
GET /api/jobs/:jobId/status
→ Returns { status, output, artifacts, ... }

// Stream job output (WebSocket)
WS /api/jobs/:jobId/stream
→ Real-time output chunks

// Get artifact
GET /api/artifacts/:jobId/:artifactName
→ Returns file (video, HTML report, etc.)
*/

// ============================================================================
// USAGE EXAMPLE
// ============================================================================

async function example() {
  const loader = new TemplateLoader();
  await loader.loadTemplatesFromDirectory('./templates/examples');
  
  const engine = new ExecutionEngine();
  
  // User wants to run a Playwright test
  const template = loader.getTemplate('playwright-test')!;
  const command: Command = {
    id: 'run-playwright-test',
    template: 'playwright-test',
    name: 'Run E2E Tests',
  };
  
  // Validate inputs
  const inputs = {
    project: 'ocp-portail-marketplace',
    testFiles: ['home.page.structure.test.ts'],
    testName: 'Smoke Test',
    browser: 'chromium',
    workers: 2,
  };
  
  const errors = loader.validateInputs(template, inputs);
  if (errors.length > 0) {
    console.error('Validation errors:', errors);
    return;
  }
  
  // Execute
  const user = { username: 'dev', roles: ['dev'] };
  const jobId = await engine.execute(template, command, inputs, user);
  
  // Listen for output
  engine.on('output', (id, chunk) => {
    if (id === jobId) {
      process.stdout.write(chunk);
    }
  });
  
  engine.on('complete', (id, result) => {
    if (id === jobId) {
      console.log(`\nJob ${result.status} with exit code ${result.exitCode}`);
    }
  });
}
