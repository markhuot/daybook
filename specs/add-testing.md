# Untested Logic

Remaining test gaps identified across controllers, models, and middleware. Timezone handling is now covered in `tests/Feature/TimezoneTest.php`.

## Auth Controllers (all untested)

### POST /login (`LoginStoreController`)

- Successful login redirects to home
- Successful login regenerates the session (prevents session fixation)
- Failed login returns back with error on the `email` field
- Failed login does not expose whether the email exists
- Validation rejects missing email
- Validation rejects missing password
- Validation rejects invalid email format
- "Remember me" checkbox sets a long-lived session
- Redirect to intended URL after login (e.g., user was redirected from a protected page)

### GET /login (`LoginController`)

- Renders the `Auth/Login` Inertia page for guests
- Authenticated users are redirected away (guest middleware)

### POST /register (`RegisterStoreController`)

- Successful registration creates a user and redirects to home
- New user is automatically logged in after registration
- Password is hashed in the database (not stored in plaintext)
- Duplicate email is rejected with a validation error
- Password confirmation mismatch is rejected
- Password strength rules are enforced (`Password::defaults()`)
- Validation rejects missing name, email, or password
- Validation rejects name longer than 255 characters
- Validation rejects email longer than 255 characters

### GET /register (`RegisterController`)

- Renders the `Auth/Register` Inertia page for guests
- Authenticated users are redirected away (guest middleware)

### POST /logout (`LogoutController`)

- Logs the user out and redirects to `/login`
- Session is invalidated after logout
- CSRF token is regenerated after logout
- Guests cannot hit POST /logout (auth middleware)

## NoteUpdateController — In-Place Update

The existing tests cover creating a new note and deleting a note, but not updating an existing today note with different content:

- `PUT /note` with new content when today's note already exists should update (not duplicate) the note
- After update, the note row count for today should still be 1

## Middleware and Config Behavior

### String Trimming Exception (`bootstrap/app.php:18-21`)

- ProseMirror `content` field values are not whitespace-trimmed by the `TrimStrings` middleware
- Nested keys under `content.*` are also preserved

### Cookie Encryption Exception (`bootstrap/app.php:23-25`)

- The `timezone` cookie is readable without Laravel decryption (client-side JS can set it)

### Model::unguard (`AppServiceProvider:25`)

- Mass assignment works for all model attributes regardless of `$fillable`

## Model-Level Logic

### User Model

- `notes()` relationship returns only notes belonging to that user
- `password` cast to `hashed` — assigning a plaintext password stores a bcrypt hash
- `$hidden` attributes — `password` and `remember_token` are excluded from JSON/array serialization (important since `auth.user` is shared via Inertia)

### Note Model

- `user()` relationship returns the owning user
- `content` cast to `array` — JSON column is automatically encoded/decoded
- Unique constraint on `[user_id, date]` — inserting a duplicate user+date pair raises a database error

## HandleInertiaRequests Middleware

- `auth.user` is shared as a prop on every Inertia page
- `auth.user` is `null` when not authenticated
- Sensitive fields (password, remember_token) are not leaked in the shared `auth.user` prop
