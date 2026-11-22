# Feature Delivery Prompt: Customer Feedback & Rating API

This prompt is a real-world example of how to instruct LaraOrcest to deliver an end-to-end Laravel feature with database models, form requests, API controllers, automated tests, and Swagger documentation.

---

## CLI Execution Command

```bash
php artisan orcest:build \
  "Create a Customer Feedback API with ratings (1 to 5), comments, approval workflow, and OpenAPI docs" \
  --swagger \
  --swagger-format=json \
  --interactive
```

---

## Detailed Prompt Body

```text
Feature Title: Customer Feedback & Rating System

Requirements:
1. Database & Persistence:
   - Create a migration for table `feedbacks`.
   - Columns:
     * id (bigIncrements)
     * user_id (foreignId nullable, constrained to users, cascade on delete)
     * author_name (string nullable - used when guest submits)
     * rating (tinyInteger unsigned: 1 to 5)
     * comment (text nullable)
     * status (enum: 'pending', 'approved', 'rejected' - default 'pending')
     * created_at, updated_at
   - Eloquent Model: App\Models\Feedback
     * Proper $fillable: ['user_id', 'author_name', 'rating', 'comment', 'status']
     * Typed casts: rating as integer, status as string
     * Relationship: belongsTo(User::class)
     * Scope: scopeApproved($query) filtering status = 'approved'

2. HTTP & Validation:
   - StoreFeedbackRequest:
     * rating: required|integer|min:1|max:5
     * comment: nullable|string|max:1000
     * author_name: nullable|string|max:100
   - UpdateFeedbackStatusRequest:
     * status: required|in:approved,rejected
   - FeedbackController (API):
     * index: GET /api/v1/feedbacks (returns paginated approved feedbacks)
     * store: POST /api/v1/feedbacks (submits new feedback, sets status to pending)
     * updateStatus: PATCH /api/v1/feedbacks/{feedback}/status (admin only, updates status)

3. Testing & Documentation:
   - Comprehensive Feature tests in tests/Feature/FeedbackApiTest.php:
     * Guests can submit feedback with valid rating.
     * Submission fails with validation error (422) if rating > 5 or rating < 1.
     * Public index only returns approved feedbacks.
     * Non-admin users cannot update feedback moderation status.
   - Generate OpenAPI 3.0+ documentation in docs/swagger.json documenting all endpoints, request bodies, and 200, 201, 404, 422 responses.
```

---

## Expected Artifacts Generated

- `database/migrations/*_create_feedbacks_table.php`
- `app/Models/Feedback.php`
- `app/Http/Requests/StoreFeedbackRequest.php`
- `app/Http/Requests/UpdateFeedbackStatusRequest.php`
- `app/Http/Controllers/Api/FeedbackController.php`
- `routes/api.php` (route binding appended)
- `tests/Feature/FeedbackApiTest.php`
- `docs/swagger.json`
