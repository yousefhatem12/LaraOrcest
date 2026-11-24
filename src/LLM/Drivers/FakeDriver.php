<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\LLM\Drivers;

use Yousef\LaraOrcest\Contracts\LlmDriverContract;

class FakeDriver implements LlmDriverContract
{
    /**
     * @var array<string, mixed>
     */
    protected array $cannedResponses = [];

    /**
     * @var array<int, array{messages: array, options: array}>
     */
    protected array $recordedCalls = [];

    /**
     * @param  array<string, mixed>  $cannedResponses
     */
    public function __construct(array $cannedResponses = [])
    {
        $this->cannedResponses = $cannedResponses;
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function setCannedResponse(string $key, mixed $response): self
    {
        $this->cannedResponses[$key] = $response;

        return $this;
    }

    public function getRecordedCalls(): array
    {
        return $this->recordedCalls;
    }

    public function chat(array $messages, array $options = []): array
    {
        $this->recordedCalls[] = ['messages' => $messages, 'options' => $options];

        $content = (string) ($this->cannedResponses['chat'] ?? 'Fake LLM completion response.');

        return [
            'content' => $content,
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 20,
                'total_tokens' => 70,
            ],
        ];
    }

    public function chatStructured(array $messages, array $schema, array $options = []): array
    {
        $this->recordedCalls[] = ['messages' => $messages, 'options' => $options, 'schema' => $schema];

        // Detect caller context from messages
        $systemMessage = '';
        $userMessage = '';
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemMessage = (string) $msg['content'];
            }
            if (($msg['role'] ?? '') === 'user') {
                $userMessage = (string) $msg['content'];
            }
        }

        // Return canned if explicitly registered
        if (isset($this->cannedResponses['structured'])) {
            $data = is_array($this->cannedResponses['structured'])
                ? $this->cannedResponses['structured']
                : (json_decode((string) $this->cannedResponses['structured'], true) ?: []);

            return [
                'data' => $data,
                'raw' => json_encode($data),
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 40, 'total_tokens' => 120],
            ];
        }

        // Deterministic mock generation based on agent role
        $data = $this->generateDeterministicResponse($systemMessage, $userMessage, $schema, $options);

        return [
            'data' => $data,
            'raw' => json_encode($data, JSON_PRETTY_PRINT),
            'usage' => [
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'total_tokens' => 150,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function generateDeterministicResponse(string $system, string $user, array $schema, array $options = []): array
    {
        $agentKey = (string) ($options['agent_key'] ?? '');

        if ($agentKey === 'reviewer_agent' || str_contains($system, 'ROLE: Code Quality, Security & Scope Reviewer')) {
            return [
                'decision' => 'approved',
                'findings' => [
                    'Architectural plan conforms to Laravel standards.',
                    'Form requests enforce strict validation.',
                    'Eloquent relationships use proper foreign keys.',
                ],
                'constraints' => [],
            ];
        }

        if ($agentKey === 'laravel_installer_agent' || str_contains($system, 'laravel_installer_agent') || str_contains($system, 'Laravel Installation & Environment Specialist')) {
            return [
                'status' => 'initialized',
                'action' => 'bootstrap_project',
                'composer_command' => 'create-project laravel/laravel . --prefer-dist --no-interaction',
                'environment_defaults' => [
                    'APP_NAME' => 'LaraOrcestApp',
                    'APP_ENV' => 'local',
                    'APP_KEY' => 'base64:fakeKeyForTestingPurposesOnly1234567890=',
                ],
                'summary' => 'Bootstrapped fresh Laravel application structure and environment configuration.',
            ];
        }

        if ($agentKey === 'dependency_resolver_agent' || str_contains($system, 'dependency_resolver_agent') || str_contains($system, 'Composer Dependency & Package Resolver')) {
            return [
                'packages' => [
                    [
                        'name' => 'spatie/laravel-permission',
                        'version' => '^6.0',
                        'dev' => false,
                        'reason' => 'Required for role and permission management according to technical plan.',
                    ],
                ],
                'summary' => 'Resolved and scheduled installation for requested Composer packages.',
            ];
        }

        if ($agentKey === 'structure_scaffolder_agent' || str_contains($system, 'structure_scaffolder_agent') || str_contains($system, 'Laravel Architecture & Directory Scaffolder')) {
            return [
                'directories' => [
                    'app/Domains/Billing',
                    'app/Domains/Billing/Models',
                    'app/Domains/Billing/Actions',
                ],
                'files' => [
                    [
                        'path' => 'app/Domains/Billing/README.md',
                        'content' => "# Billing Domain\n\nModular domain for billing and invoicing.\n",
                        'summary' => 'Initial documentation for Billing domain module.',
                    ],
                ],
                'summary' => 'Scaffolded modular Domain-Driven Design directory structure.',
            ];
        }

        if ($agentKey === 'package_manager_agent' || str_contains($system, 'package_manager_agent') || str_contains($system, 'Laravel Package Manager & Project Bootstrapper')) {
            return [
                'bootstrap' => [
                    'status' => 'initialized',
                    'action' => 'bootstrap_project',
                    'summary' => 'Laravel project verified and initialized.',
                ],
                'packages' => [
                    [
                        'name' => 'spatie/laravel-permission',
                        'version' => '^6.0',
                        'dev' => false,
                        'reason' => 'Role and permission management.',
                    ],
                ],
                'directories' => [
                    'app/Domains/Billing',
                    'app/Domains/Billing/Models',
                ],
                'files' => [
                    [
                        'path' => 'app/Domains/Billing/README.md',
                        'content' => "# Billing Domain\n\nModular domain for billing and invoicing.\n",
                        'summary' => 'Initial documentation for Billing domain module.',
                    ],
                ],
                'summary' => 'Bootstrapped target Laravel project, required packages, and scaffolded modular directories.',
            ];
        }

        if (str_contains($system, 'Domain Rules') || str_contains($system, 'domain_rules_agent')) {
            return [
                'business_rules' => [
                    'A task must belong to a valid project',
                    'Only project members can be assigned to tasks',
                ],
                'state_transitions' => [
                    [
                        'entity' => 'Task',
                        'from_state' => 'pending',
                        'to_state' => 'in_progress',
                        'condition' => 'user_assigned',
                    ],
                    [
                        'entity' => 'Task',
                        'from_state' => 'in_progress',
                        'to_state' => 'completed',
                        'condition' => 'review_approved',
                    ],
                ],
                'edge_cases' => [
                    'Deleting a project should handle orphaned tasks',
                    'Reassigning task to a removed project member should be blocked',
                ],
                'ambiguities' => [],
            ];
        }

        if (str_contains($system, 'Task Decomposition') || str_contains($system, 'backlog_splitter_agent')) {
            return [
                'tasks' => [
                    [
                        'id' => 'TASK-1',
                        'title' => 'Create Task and Project database migrations and models',
                        'description' => 'Define tables, relationships, and model casts.',
                        'priority' => 1,
                        'acceptance_criteria' => [
                            'Projects table has name, description, user_id',
                            'Tasks table has title, status, project_id, assigned_user_id',
                            'Eloquent relationships defined between Project, Task, and User',
                        ],
                        'depends_on' => [],
                        'scope_paths' => ['database/migrations/**', 'app/Models/**'],
                        'risk_assessment' => 'Low risk, standard structure',
                    ],
                    [
                        'id' => 'TASK-2',
                        'title' => 'Implement Task CRUD API controllers and routes',
                        'description' => 'Expose endpoints with validation requests.',
                        'priority' => 2,
                        'acceptance_criteria' => [
                            'GET /api/tasks lists tasks with project relation',
                            'POST /api/tasks validates title and project_id',
                        ],
                        'depends_on' => ['TASK-1'],
                        'scope_paths' => ['app/Http/Controllers/**', 'routes/**'],
                        'risk_assessment' => 'Medium risk, requires validation',
                    ],
                    [
                        'id' => 'TASK-3',
                        'title' => 'Automated test suite for Task feature',
                        'description' => 'Feature tests verifying validation and business logic.',
                        'priority' => 3,
                        'acceptance_criteria' => [
                            'Tests pass with HTTP 200/201 on happy paths',
                            'Tests verify HTTP 422 on invalid payload',
                        ],
                        'depends_on' => ['TASK-2'],
                        'scope_paths' => ['tests/**'],
                        'risk_assessment' => 'Low risk, standard tests',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'Web Scraping') || str_contains($system, 'web_scraping_agent')) {
            return [
                'extraction_summary' => 'Extracted relevant entities and attributes from both target websites',
                'site_1_data' => ['source' => 'site1', 'items' => [['id' => 1, 'name' => 'Item 1']]],
                'site_2_data' => ['source' => 'site2', 'items' => [['id' => 2, 'name' => 'Item 2']]],
                'unified_payload' => [
                    'source' => 'lara_orcest_web_scraper',
                    'items' => [
                        ['id' => 1, 'name' => 'Item 1'],
                        ['id' => 2, 'name' => 'Item 2'],
                    ],
                ],
                'warnings' => [],
            ];
        }

        if (str_contains($system, 'Business Analyst')) {
            return [
                'domain_entities' => ['Task', 'Project', 'User'],
                'functional_requirements' => [
                    'Users can create and assign tasks within projects',
                    'Tasks have statuses: pending, in_progress, completed',
                ],
                'business_rules' => [
                    'A task must belong to a valid project',
                    'Only project members can be assigned to tasks',
                ],
                'roles_and_permissions' => [
                    'admin' => ['manage_projects', 'manage_tasks'],
                    'member' => ['view_tasks', 'update_assigned_tasks'],
                ],
                'edge_cases' => [
                    'Deleting a project should handle orphaned tasks',
                    'Reassigning task to a removed project member should be blocked',
                ],
                'non_functional' => [
                    'Fast Eloquent eager-loading to avoid N+1 queries',
                ],
            ];
        }

        if (str_contains($system, 'Product Manager')) {
            return [
                'tasks' => [
                    [
                        'id' => 'TASK-1',
                        'title' => 'Create Task and Project database migrations and models',
                        'description' => 'Define tables, relationships, and model casts.',
                        'priority' => 1,
                        'acceptance_criteria' => [
                            'Projects table has name, description, user_id',
                            'Tasks table has title, status, project_id, assigned_user_id',
                            'Eloquent relationships defined between Project, Task, and User',
                        ],
                    ],
                    [
                        'id' => 'TASK-2',
                        'title' => 'Implement Task CRUD API controllers and routes',
                        'description' => 'Expose endpoints with validation requests.',
                        'priority' => 2,
                        'acceptance_criteria' => [
                            'GET /api/tasks lists tasks with project relation',
                            'POST /api/tasks validates title and project_id',
                        ],
                    ],
                    [
                        'id' => 'TASK-3',
                        'title' => 'Automated test suite for Task feature',
                        'description' => 'Feature tests verifying validation and business logic.',
                        'priority' => 3,
                        'acceptance_criteria' => [
                            'Tests pass with HTTP 200/201 on happy paths',
                            'Tests verify HTTP 422 on invalid payload',
                        ],
                    ],
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Modular DDD Architecture') || str_contains($system, 'ddd_boundary_agent')) {
            return [
                'bounded_contexts' => [
                    [
                        'name' => 'TaskManagement',
                        'description' => 'Core task tracking and project assignment context',
                        'entities' => ['Task', 'Project'],
                    ],
                    [
                        'name' => 'Identity',
                        'description' => 'User identity and membership context',
                        'entities' => ['User', 'Membership'],
                    ],
                ],
                'directory_structure' => [
                    'app/Models' => 'Core Eloquent entities',
                    'app/Services' => 'Domain services and workflows',
                    'app/Http/Controllers/Api' => 'REST API endpoints',
                ],
                'domain_services' => [
                    [
                        'class' => 'App\\Services\\TaskAssignmentService',
                        'responsibility' => 'Validates project membership before assigning a task',
                    ],
                ],
                'tenant_isolation_strategy' => 'Foreign key constraint project_id on all child entities with global scope',
                'architectural_rules' => [
                    'Controllers must only delegate to FormRequests and Services',
                    'Direct mutation of related entities must go through domain actions',
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Relational Database Schema') || str_contains($system, 'schema_planner_agent')) {
            return [
                'tables' => [
                    [
                        'table' => 'projects',
                        'columns' => [
                            ['name' => 'id', 'type' => 'bigIncrements', 'nullable' => false],
                            ['name' => 'name', 'type' => 'string', 'nullable' => false],
                            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true],
                            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true],
                        ],
                        'foreign_keys' => [],
                        'indexes' => ['name'],
                    ],
                    [
                        'table' => 'tasks',
                        'columns' => [
                            ['name' => 'id', 'type' => 'bigIncrements', 'nullable' => false],
                            ['name' => 'project_id', 'type' => 'unsignedBigInteger', 'nullable' => false],
                            ['name' => 'title', 'type' => 'string', 'nullable' => false],
                            ['name' => 'status', 'type' => 'string', 'nullable' => false],
                            ['name' => 'created_at', 'type' => 'timestamp', 'nullable' => true],
                            ['name' => 'updated_at', 'type' => 'timestamp', 'nullable' => true],
                        ],
                        'foreign_keys' => ['foreign(project_id).references(id).on(projects).cascadeOnDelete()'],
                        'indexes' => ['status', 'project_id_status'],
                    ],
                ],
                'migrations' => [
                    'create_projects_table' => 'database/migrations/2026_01_01_000001_create_projects_table.php',
                    'create_tasks_table' => 'database/migrations/2026_01_01_000002_create_tasks_table.php',
                ],
                'models' => [
                    'Project' => 'app/Models/Project.php',
                    'Task' => 'app/Models/Task.php',
                ],
                'migration_order' => [
                    'database/migrations/2026_01_01_000001_create_projects_table.php',
                    'database/migrations/2026_01_01_000002_create_tasks_table.php',
                ],
                'indexing_strategy' => [
                    'Composite index on tasks (project_id, status) for filtered list queries',
                ],
            ];
        }

        if (str_contains($system, 'ROLE: REST API Contract') || str_contains($system, 'api_contract_agent')) {
            return [
                'endpoints' => [
                    [
                        'method' => 'GET',
                        'uri' => '/api/projects',
                        'controller_action' => 'App\\Http\\Controllers\\Api\\ProjectController@index',
                        'request_class' => '',
                        'resource_class' => 'App\\Http\\Resources\\ProjectResource',
                        'auth_middleware' => 'auth:sanctum',
                        'status_codes' => [200],
                    ],
                    [
                        'method' => 'POST',
                        'uri' => '/api/tasks',
                        'controller_action' => 'App\\Http\\Controllers\\Api\\TaskController@store',
                        'request_class' => 'App\\Http\\Requests\\StoreTaskRequest',
                        'resource_class' => 'App\\Http\\Resources\\TaskResource',
                        'auth_middleware' => 'auth:sanctum',
                        'status_codes' => [201, 422],
                    ],
                ],
                'controllers' => [
                    'ProjectController' => 'app/Http/Controllers/Api/ProjectController.php',
                    'TaskController' => 'app/Http/Controllers/Api/TaskController.php',
                ],
                'requests' => [
                    'StoreTaskRequest' => 'app/Http/Requests/StoreTaskRequest.php',
                ],
                'resources' => [
                    'ProjectResource' => 'app/Http/Resources/ProjectResource.php',
                    'TaskResource' => 'app/Http/Resources/TaskResource.php',
                ],
                'policies' => [
                    'TaskPolicy' => 'app/Policies/TaskPolicy.php',
                ],
                'routes' => [
                    'routes/api.php',
                ],
                'error_contracts' => [
                    'validation_error' => ['message' => 'The given data was invalid.', 'errors' => []],
                ],
            ];
        }

        if (str_contains($system, 'Software Architect')) {
            return [
                'migrations' => [
                    'create_projects_table' => 'database/migrations/2026_01_01_000001_create_projects_table.php',
                    'create_tasks_table' => 'database/migrations/2026_01_01_000002_create_tasks_table.php',
                ],
                'models' => [
                    'Project' => 'app/Models/Project.php',
                    'Task' => 'app/Models/Task.php',
                ],
                'controllers' => [
                    'TaskController' => 'app/Http/Controllers/Api/TaskController.php',
                ],
                'requests' => [
                    'StoreTaskRequest' => 'app/Http/Requests/StoreTaskRequest.php',
                ],
                'routes' => [
                    'routes/api.php',
                ],
                'tests' => [
                    'TaskApiTest' => 'tests/Feature/TaskApiTest.php',
                ],
                'execution_steps' => [
                    'Create migrations and models',
                    'Create requests and controllers',
                    'Register routes',
                    'Write feature tests',
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Laravel Database Migration Specialist') || str_contains($system, 'migration_agent')) {
            return [
                'files' => [
                    [
                        'path' => 'database/migrations/2026_01_01_000001_create_projects_table.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void {\n        Schema::create('projects', function (Blueprint \$table) {\n            \$table->id();\n            \$table->string('name');\n            \$table->timestamps();\n        });\n    }\n\n    public function down(): void {\n        Schema::dropIfExists('projects');\n    }\n};\n",
                        'summary' => 'Create projects table migration',
                    ],
                    [
                        'path' => 'database/migrations/2026_01_01_000002_create_tasks_table.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void {\n        Schema::create('tasks', function (Blueprint \$table) {\n            \$table->id();\n            \$table->foreignId('project_id')->constrained()->cascadeOnDelete();\n            \$table->string('title');\n            \$table->string('status')->default('pending');\n            \$table->timestamps();\n        });\n    }\n\n    public function down(): void {\n        Schema::dropIfExists('tasks');\n    }\n};\n",
                        'summary' => 'Create tasks table migration',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Eloquent Model & Factory Specialist') || str_contains($system, 'model_eloquent_agent')) {
            return [
                'files' => [
                    [
                        'path' => 'app/Models/Project.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\HasMany;\n\nclass Project extends Model\n{\n    use HasFactory;\n\n    protected \$fillable = ['name'];\n\n    public function tasks(): HasMany\n    {\n        return \$this->hasMany(Task::class);\n    }\n}\n",
                        'summary' => 'Project Eloquent model with hasMany tasks relationship',
                    ],
                    [
                        'path' => 'app/Models/Task.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;\n\nclass Task extends Model\n{\n    use HasFactory;\n\n    protected \$fillable = ['project_id', 'title', 'status'];\n\n    public function project(): BelongsTo\n    {\n        return \$this->belongsTo(Project::class);\n    }\n}\n",
                        'summary' => 'Task Eloquent model with belongsTo project relationship',
                    ],
                    [
                        'path' => 'database/factories/ProjectFactory.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Database\\Factories;\n\nuse App\\Models\\Project;\nuse Illuminate\\Database\\Eloquent\\Factories\\Factory;\n\nclass ProjectFactory extends Factory\n{\n    protected \$model = Project::class;\n\n    public function definition(): array\n    {\n        return ['name' => fake()->sentence(3)];\n    }\n}\n",
                        'summary' => 'Project factory',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Idempotent Database Seeder Specialist') || str_contains($system, 'seeder_agent')) {
            return [
                'files' => [
                    [
                        'path' => 'database/seeders/ProjectSeeder.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Database\\Seeders;\n\nuse App\\Models\\Project;\nuse Illuminate\\Database\\Seeder;\n\nclass ProjectSeeder extends Seeder\n{\n    public function run(): void\n    {\n        Project::firstOrCreate(['name' => 'Demo Project']);\n    }\n}\n",
                        'summary' => 'Idempotent project seeder',
                    ],
                    [
                        'path' => 'database/seeders/DatabaseSeeder.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\n\nclass DatabaseSeeder extends Seeder\n{\n    public function run(): void\n    {\n        \$this->call([ProjectSeeder::class]);\n    }\n}\n",
                        'summary' => 'Root database seeder',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'Database & Eloquent Specialist')) {
            return [
                'files' => [
                    [
                        'path' => 'database/migrations/2026_01_01_000001_create_projects_table.php',
                        'content' => "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n\nreturn new class extends Migration {\n    public function up(): void {\n        Schema::create('projects', function (Blueprint \$table) {\n            \$table->id();\n            \$table->string('name');\n            \$table->timestamps();\n        });\n    }\n};\n",
                        'summary' => 'Create projects table migration',
                    ],
                    [
                        'path' => 'app/Models/Project.php',
                        'content' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Project extends Model\n{\n    protected \$guarded = [];\n}\n",
                        'summary' => 'Project Eloquent model',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Laravel FormRequest & Authorization Specialist') || str_contains($system, 'request_validation_agent')) {
            return [
                'files' => [
                    [
                        'path' => 'app/Http/Requests/StoreTaskRequest.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass StoreTaskRequest extends FormRequest\n{\n    public function authorize(): bool\n    {\n        return true;\n    }\n\n    public function rules(): array\n    {\n        return [\n            'title' => ['required', 'string', 'max:255'],\n            'project_id' => ['required', 'integer'],\n        ];\n    }\n}\n",
                        'summary' => 'StoreTaskRequest validation class',
                    ],
                    [
                        'path' => 'app/Policies/TaskPolicy.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Policies;\n\nuse App\\Models\\User;\n\nclass TaskPolicy\n{\n    public function viewAny(User \$user): bool\n    {\n        return true;\n    }\n}\n",
                        'summary' => 'TaskPolicy authorization policy',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Domain Action & Service Workflow Specialist') || str_contains($system, 'service_action_agent')) {
            return [
                'files' => [
                    [
                        'path' => 'app/Actions/CreateTaskAction.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Actions;\n\nuse App\\Models\\Task;\nuse Illuminate\\Support\\Facades\\DB;\n\nclass CreateTaskAction\n{\n    public function execute(array \$data): Task\n    {\n        return DB::transaction(function () use (\$data) {\n            return Task::create(\$data);\n        });\n    }\n}\n",
                        'summary' => 'CreateTaskAction single responsibility action class',
                    ],
                    [
                        'path' => 'app/Services/TaskAssignmentService.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Services;\n\nuse App\\Models\\Task;\n\nclass TaskAssignmentService\n{\n    public function assign(Task \$task, int \$userId): Task\n    {\n        \$task->update(['assigned_user_id' => \$userId]);\n        return \$task;\n    }\n}\n",
                        'summary' => 'TaskAssignmentService domain workflow service',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'ROLE: Controller, API Resource & Route Specialist') || str_contains($system, 'controller_resource_agent')) {
            return [
                'files' => [
                    [
                        'path' => 'app/Http/Controllers/Api/TaskController.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Controllers\\Api;\n\nuse App\\Actions\\CreateTaskAction;\nuse App\\Http\\Requests\\StoreTaskRequest;\nuse App\\Http\\Resources\\TaskResource;\nuse App\\Models\\Task;\nuse Illuminate\\Http\\JsonResponse;\nuse Illuminate\\Routing\\Controller;\n\nclass TaskController extends Controller\n{\n    public function index(): JsonResponse\n    {\n        return response()->json(['data' => Task::all()]);\n    }\n\n    public function store(StoreTaskRequest \$request, CreateTaskAction \$action): JsonResponse\n    {\n        \$task = \$action->execute(\$request->validated());\n        return (new TaskResource(\$task))->response()->setStatusCode(201);\n    }\n}\n",
                        'summary' => 'Task API Controller with thin action delegation',
                    ],
                    [
                        'path' => 'app/Http/Resources/TaskResource.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Resources;\n\nuse Illuminate\\Http\\Request;\nuse Illuminate\\Http\\Resources\\Json\\JsonResource;\n\nclass TaskResource extends JsonResource\n{\n    public function toArray(Request \$request): array\n    {\n        return [\n            'id' => \$this->id,\n            'title' => \$this->title,\n            'status' => \$this->status,\n        ];\n    }\n}\n",
                        'summary' => 'TaskResource API response transformer',
                    ],
                    [
                        'path' => 'routes/api.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Http\\Controllers\\Api\\TaskController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::apiResource('tasks', TaskController::class);\n",
                        'summary' => 'API routes registration for tasks resource',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'Backend & API Engineer')) {
            return [
                'files' => [
                    [
                        'path' => 'app/Http/Controllers/Api/TaskController.php',
                        'content' => "<?php\n\nnamespace App\\Http\\Controllers\\Api;\n\nuse Illuminate\\Http\\JsonResponse;\nuse Illuminate\\Routing\\Controller;\n\nclass TaskController extends Controller\n{\n    public function index(): JsonResponse\n    {\n        return response()->json(['data' => []]);\n    }\n}\n",
                        'summary' => 'Task API Controller',
                    ],
                ],
            ];
        }

        if ($agentKey === 'qa_agent' || $agentKey === 'feature_test_agent' || str_contains($system, 'ROLE: Laravel Feature & Unit Test Specialist') || str_contains($system, 'feature_test_agent') || str_contains($system, 'Automated QA & Test Engineer')) {
            return [
                'test_files' => [
                    [
                        'path' => 'tests/Feature/TaskApiTest.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace Tests\\Feature;\n\nuse Tests\\TestCase;\n\nclass TaskApiTest extends TestCase\n{\n    public function test_tasks_can_be_retrieved(): void\n    {\n        \$response = \$this->getJson('/api/tasks');\n        \$response->assertStatus(200);\n    }\n}\n",
                        'summary' => 'Task API Feature Test',
                    ],
                ],
            ];
        }

        if ($agentKey === 'swagger_agent' || str_contains($system, 'ROLE: Laravel OpenAPI & Swagger Documentation Specialist') || str_contains($system, 'ROLE: Laravel Swagger & OpenAPI Documentation Specialist')) {
            $targetPath = 'docs/swagger.json';
            if (preg_match('#"target_path":\s*"([^"]+)"#', $user, $matches)) {
                $targetPath = $matches[1];
            }

            $spec = [
                'openapi' => '3.0.3',
                'info' => [
                    'title' => 'Laravel Application API Documentation',
                    'version' => '1.0.0',
                    'description' => 'Automated OpenAPI 3.0 specification generated by LaraOrcest Swagger Agent.',
                ],
                'paths' => [
                    '/api/tasks' => [
                        'get' => [
                            'tags' => ['Tasks'],
                            'summary' => 'List tasks',
                            'description' => 'Retrieve a paginated list of tasks filtered by project or status.',
                            'parameters' => [
                                [
                                    'name' => 'status',
                                    'in' => 'query',
                                    'required' => false,
                                    'description' => 'Filter tasks by status (e.g. pending, completed)',
                                    'schema' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed']],
                                ],
                            ],
                            'responses' => [
                                '200' => [
                                    'description' => 'Successful operation',
                                    'content' => [
                                        'application/json' => [
                                            'example' => [
                                                'data' => [
                                                    ['id' => 1, 'title' => 'First Task', 'status' => 'pending'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                '401' => [
                                    'description' => 'Unauthenticated',
                                    'content' => [
                                        'application/json' => [
                                            'example' => ['message' => 'Unauthenticated.'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'post' => [
                            'tags' => ['Tasks'],
                            'summary' => 'Create a task',
                            'description' => 'Create a new task under a project validated by StoreTaskRequest.',
                            'requestBody' => [
                                'required' => true,
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['title', 'project_id'],
                                            'properties' => [
                                                'title' => ['type' => 'string', 'maxLength' => 255],
                                                'project_id' => ['type' => 'integer'],
                                                'status' => ['type' => 'string', 'enum' => ['pending', 'in_progress', 'completed'], 'default' => 'pending'],
                                            ],
                                        ],
                                        'example' => [
                                            'title' => 'Implement Swagger Documentation',
                                            'project_id' => 1,
                                            'status' => 'pending',
                                        ],
                                    ],
                                ],
                            ],
                            'responses' => [
                                '201' => [
                                    'description' => 'Task created successfully',
                                    'content' => [
                                        'application/json' => [
                                            'example' => [
                                                'id' => 2,
                                                'title' => 'Implement Swagger Documentation',
                                                'project_id' => 1,
                                                'status' => 'pending',
                                            ],
                                        ],
                                    ],
                                ],
                                '400' => [
                                    'description' => 'Bad request',
                                    'content' => [
                                        'application/json' => [
                                            'example' => ['message' => 'Invalid JSON payload.'],
                                        ],
                                    ],
                                ],
                                '401' => [
                                    'description' => 'Unauthenticated',
                                    'content' => [
                                        'application/json' => [
                                            'example' => ['message' => 'Unauthenticated.'],
                                        ],
                                    ],
                                ],
                                '404' => [
                                    'description' => 'Project not found',
                                    'content' => [
                                        'application/json' => [
                                            'example' => ['message' => 'Target project not found.'],
                                        ],
                                    ],
                                ],
                                '422' => [
                                    'description' => 'Validation error',
                                    'content' => [
                                        'application/json' => [
                                            'example' => [
                                                'message' => 'The title field is required.',
                                                'errors' => [
                                                    'title' => ['The title field is required.'],
                                                    'project_id' => ['The project id field is required.'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'components' => [
                    'schemas' => [
                        'Task' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ];

            $isYaml = str_ends_with(strtolower($targetPath), '.yaml') || str_ends_with(strtolower($targetPath), '.yml');
            $fileContent = $isYaml
                ? "openapi: 3.0.3\ninfo:\n  title: \"Laravel Application API Documentation\"\n  version: \"1.0.0\"\npaths:\n  /api/tasks:\n    get:\n      summary: \"List tasks\"\n"
                : json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return [
                'openapi_spec' => $spec,
                'files' => [
                    [
                        'path' => $targetPath,
                        'content' => $fileContent,
                        'summary' => 'Swagger OpenAPI specification document',
                    ],
                ],
                'endpoints_documented' => [
                    'GET /api/tasks',
                    'POST /api/tasks',
                ],
                'summary' => 'Generated OpenAPI 3.0 Swagger documentation for backend endpoints.',
            ];
        }

        if ($agentKey === 'git_agent' || str_contains($system, 'ROLE: Git Branch & Milestone Release Specialist') || str_contains($system, 'ROLE: Source Control & Release Hygiene Agent') || str_contains($system, 'git_agent')) {
            return [
                'branch_name' => 'orcest/feature-task-management',
                'commit_message' => 'feat(tasks): implement task management API and feature tests',
                'milestone_summary' => 'Created feature branch and committed verified milestone',
            ];
        }

        if ($agentKey === 'reviewer_agent' || str_contains($system, 'ROLE: Code Quality, Security & Scope Reviewer') || str_contains($system, 'Reviewer') || str_contains($system, 'reviewer_agent')) {
            return [
                'decision' => 'approved',
                'findings' => [
                    'Architectural plan conforms to Laravel standards.',
                    'Form requests enforce strict validation.',
                    'Eloquent relationships use proper foreign keys.',
                ],
                'constraints' => [],
            ];
        }

        if ($agentKey === 'fixer_agent' || str_contains($system, 'ROLE: Root-Cause Bug Fixer & Test Debugger') || str_contains($system, 'ROLE: Root-Cause Bug Fixer & Debugger') || str_contains($system, 'fixer_agent') || str_contains($system, 'Fixer')) {
            return [
                'root_cause' => 'Minor validation rule adjustment needed.',
                'fixed_files' => [
                    [
                        'path' => 'app/Http/Requests/StoreTaskRequest.php',
                        'content' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Http\\Requests;\n\nuse Illuminate\\Foundation\\Http\\FormRequest;\n\nclass StoreTaskRequest extends FormRequest\n{\n    public function authorize(): bool\n    {\n        return true;\n    }\n\n    public function rules(): array\n    {\n        return [\n            'title' => ['required', 'string', 'max:255'],\n            'project_id' => ['required', 'integer'],\n        ];\n    }\n}\n",
                        'summary' => 'Fixed StoreTaskRequest validation rules',
                    ],
                ],
            ];
        }

        return ['status' => 'success', 'data' => []];
    }
}
