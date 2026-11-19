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
        $data = $this->generateDeterministicResponse($systemMessage, $userMessage, $schema);

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
     * @return array<string, mixed>
     */
    protected function generateDeterministicResponse(string $system, string $user, array $schema): array
    {
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

        if (str_contains($system, 'Automated QA & Test Engineer')) {
            return [
                'test_files' => [
                    [
                        'path' => 'tests/Feature/TaskApiTest.php',
                        'content' => "<?php\n\nnamespace Tests\\Feature;\n\nuse Tests\\TestCase;\n\nclass TaskApiTest extends TestCase\n{\n    public function test_tasks_can_be_retrieved(): void\n    {\n        \$response = \$this->getJson('/api/tasks');\n        \$response->assertStatus(200);\n    }\n}\n",
                        'summary' => 'Task API Feature Test',
                    ],
                ],
            ];
        }

        if (str_contains($system, 'Reviewer')) {
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

        if (str_contains($system, 'Fixer')) {
            return [
                'root_cause' => 'Minor validation rule adjustment needed.',
                'applied_fix' => 'Updated StoreTaskRequest rules and TaskController response.',
                'status' => 'fixed',
            ];
        }

        return ['status' => 'success', 'data' => []];
    }
}
