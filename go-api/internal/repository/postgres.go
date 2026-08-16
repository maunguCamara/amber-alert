package repository

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/google/uuid"
	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"
	"example.com/amberalert/internal/models"
)

// ── Pool ─────────────────────────────────────────────────────────────────────

func NewPool(dsn string) (*pgxpool.Pool, error) {
	cfg, err := pgxpool.ParseConfig(dsn)
	if err != nil {
		return nil, fmt.Errorf("parse db config: %w", err)
	}
	cfg.MaxConns = 25
	cfg.MinConns = 5
	cfg.MaxConnLifetime = 1 * time.Hour
	cfg.MaxConnIdleTime = 10 * time.Minute

	pool, err := pgxpool.NewWithConfig(context.Background(), cfg)
	if err != nil {
		return nil, fmt.Errorf("create pool: %w", err)
	}
	if err := pool.Ping(context.Background()); err != nil {
		return nil, fmt.Errorf("ping db: %w", err)
	}
	return pool, nil
}

// ── Case Repository ───────────────────────────────────────────────────────────

type CaseRepo struct{ db *pgxpool.Pool }

func NewCaseRepo(db *pgxpool.Pool) *CaseRepo { return &CaseRepo{db: db} }

func (r *CaseRepo) Create(ctx context.Context, c *models.Case) error {
	const query = `
	INSERT INTO cases (
		id, reference_no, child_name, age, gender, height_cm, weight_kg,
		complexion, clothing, distinctive, languages,
		last_seen_area, county, sub_county,
		location,
		description, missing_since, circumstance_type,
		status, reporter_id, reporter_type, contact_phone,
		created_by, created_at, updated_at
	) VALUES (
		$1, $2, $3, $4, $5, $6, $7,
		$8, $9, $10, $11,
		$12, $13, $14,
		ST_SetSRID(ST_MakePoint($16, $15), 4326)::geography,
		$17, $18, $19,
		$20, $21, $22, $23,
		$24, NOW(), NOW()
	)
	RETURNING created_at, updated_at, reference_no`

	c.ID = uuid.New()

	return r.db.QueryRow(ctx, query,
		c.ID, c.ReferenceNo, c.ChildName, c.Age, c.Gender, c.HeightCM, c.WeightKG,
		c.Complexion, c.Clothing, c.Distinctive, c.Languages,
		c.LastSeenArea, c.County, c.SubCounty,
		c.LastSeenLat, c.LastSeenLng,
		c.Description, c.MissingSince, c.CircumstanceType,
		c.Status, c.ReporterID, c.ReporterType, c.ContactPhone,
		c.CreatedBy,
	).Scan(&c.CreatedAt, &c.UpdatedAt, &c.ReferenceNo)
}

func (r *CaseRepo) GetByID(ctx context.Context, id uuid.UUID) (*models.Case, error) {
	const query = `
	SELECT
		c.id, c.reference_no, c.child_name, c.age, c.gender,
		c.height_cm, c.weight_kg, c.complexion, c.clothing, c.distinctive, c.languages,
		c.last_seen_area, c.county, c.sub_county,
		ST_Y(c.location::geometry) AS lat,
		ST_X(c.location::geometry) AS lng,
		c.description, c.missing_since, c.circumstance_type,
		c.status, c.resolved_at, c.resolution,
		c.reporter_id, c.reporter_type, c.contact_phone,
		c.created_at, c.updated_at, c.created_by
	FROM cases c
	WHERE c.id = $1 AND c.deleted_at IS NULL`

	var c models.Case
	err := r.db.QueryRow(ctx, query, id).Scan(
		&c.ID, &c.ReferenceNo, &c.ChildName, &c.Age, &c.Gender,
		&c.HeightCM, &c.WeightKG, &c.Complexion, &c.Clothing, &c.Distinctive, &c.Languages,
		&c.LastSeenArea, &c.County, &c.SubCounty,
		&c.LastSeenLat, &c.LastSeenLng,
		&c.Description, &c.MissingSince, &c.CircumstanceType,
		&c.Status, &c.ResolvedAt, &c.Resolution,
		&c.ReporterID, &c.ReporterType, &c.ContactPhone,
		&c.CreatedAt, &c.UpdatedAt, &c.CreatedBy,
	)
	if err != nil {
		if errors.Is(err, pgx.ErrNoRows) {
			return nil, ErrNotFound
		}
		return nil, fmt.Errorf("get case: %w", err)
	}

	// Attach photos — log but do not fail if media fetch errors
	photos, err := r.mediaRepo().ByCaseID(ctx, id)
	if err == nil {
		c.Photos = photos
	}
	return &c, nil
}

// ListGeoPoints returns lightweight records for map rendering.
//
// FIX T-04 / T-29: status and county are passed as parameterised arguments
// ($3 and $4) instead of being interpolated into the query string with
// fmt.Sprintf. This eliminates the SQL injection vulnerability.
func (r *CaseRepo) ListGeoPoints(ctx context.Context, filter CaseFilter) ([]models.CaseGeoPoint, error) {
	// Build the argument list. $1=limit, $2=offset are always present.
	// $3=status and $4=county are added conditionally.
	args := []any{filter.Limit, filter.Offset}

	statusClause := ""
	if filter.Status != nil {
		args = append(args, string(*filter.Status))
		statusClause = fmt.Sprintf(" AND c.status = $%d", len(args))
	}

	countyClause := ""
	if filter.County != "" {
		args = append(args, filter.County)
		countyClause = fmt.Sprintf(" AND c.county = $%d", len(args))
	}

	query := `
	SELECT
		c.id, c.reference_no, c.child_name, c.age, c.gender, c.status,
		c.county,
		ST_Y(c.location::geometry) AS lat,
		ST_X(c.location::geometry) AS lng,
		c.missing_since,
		m.thumb_url
	FROM cases c
	LEFT JOIN media m ON m.case_id = c.id AND m.is_primary = TRUE
	WHERE c.deleted_at IS NULL` +
		statusClause +
		countyClause + `
	ORDER BY c.missing_since DESC
	LIMIT $1 OFFSET $2`

	rows, err := r.db.Query(ctx, query, args...)
	if err != nil {
		return nil, fmt.Errorf("list geo points: %w", err)
	}
	defer rows.Close()

	var points []models.CaseGeoPoint
	for rows.Next() {
		var p models.CaseGeoPoint
		if err := rows.Scan(
			&p.ID, &p.ReferenceNo, &p.ChildName, &p.Age, &p.Gender,
			&p.Status, &p.County, &p.Lat, &p.Lng,
			&p.MissingSince, &p.ThumbnailURL,
		); err != nil {
			return nil, fmt.Errorf("scan geo point: %w", err)
		}
		points = append(points, p)
	}
	return points, rows.Err()
}

func (r *CaseRepo) UpdateStatus(ctx context.Context, id uuid.UUID, status models.CaseStatus, resolution string, updatedBy uuid.UUID) error {
	var resolvedAt *time.Time
	if status == models.CaseStatusResolved || status == models.CaseStatusClosed {
		now := time.Now()
		resolvedAt = &now
	}
	_, err := r.db.Exec(ctx, `
		UPDATE cases
		SET status = $1, resolution = $2, resolved_at = $3, updated_by = $4, updated_at = NOW()
		WHERE id = $5 AND deleted_at IS NULL`,
		status, resolution, resolvedAt, updatedBy, id,
	)
	return err
}

func (r *CaseRepo) NearbyActiveCases(ctx context.Context, lat, lng, radiusMetres float64) ([]models.CaseGeoPoint, error) {
	const query = `
	SELECT
		c.id, c.reference_no, c.child_name, c.age, c.gender, c.status,
		c.county,
		ST_Y(c.location::geometry) AS lat,
		ST_X(c.location::geometry) AS lng,
		c.missing_since,
		m.thumb_url
	FROM cases c
	LEFT JOIN media m ON m.case_id = c.id AND m.is_primary = TRUE
	WHERE c.status = 'active'
	  AND c.deleted_at IS NULL
	  AND ST_DWithin(
		  c.location,
		  ST_SetSRID(ST_MakePoint($2, $1), 4326)::geography,
		  $3
	  )
	ORDER BY c.location <-> ST_SetSRID(ST_MakePoint($2, $1), 4326)::geography`

	rows, err := r.db.Query(ctx, query, lat, lng, radiusMetres)
	if err != nil {
		return nil, fmt.Errorf("nearby active cases: %w", err)
	}
	defer rows.Close()

	var results []models.CaseGeoPoint
	for rows.Next() {
		var p models.CaseGeoPoint
		if err := rows.Scan(
			&p.ID, &p.ReferenceNo, &p.ChildName, &p.Age, &p.Gender,
			&p.Status, &p.County, &p.Lat, &p.Lng, &p.MissingSince, &p.ThumbnailURL,
		); err != nil {
			return nil, fmt.Errorf("scan nearby point: %w", err)
		}
		results = append(results, p)
	}
	return results, rows.Err()
}

func (r *CaseRepo) GenerateReferenceNo(ctx context.Context) (string, error) {
	var seq int
	if err := r.db.QueryRow(ctx, `SELECT nextval('case_reference_seq')`).Scan(&seq); err != nil {
		return "", fmt.Errorf("generate reference number: %w", err)
	}
	return fmt.Sprintf("KE-%d-%05d", time.Now().Year(), seq), nil
}

func (r *CaseRepo) mediaRepo() *MediaRepo { return &MediaRepo{db: r.db} }

// ── Media Repository ──────────────────────────────────────────────────────────

type MediaRepo struct{ db *pgxpool.Pool }

func NewMediaRepo(db *pgxpool.Pool) *MediaRepo { return &MediaRepo{db: db} }

func (r *MediaRepo) Insert(ctx context.Context, m *models.Media) error {
	m.ID = uuid.New()
	return r.db.QueryRow(ctx, `
		INSERT INTO media (id, case_id, url, thumb_url, mime_type, size_bytes, is_primary, created_at)
		VALUES ($1, $2, $3, $4, $5, $6, $7, NOW())
		RETURNING created_at`,
		m.ID, m.CaseID, m.URL, m.ThumbURL, m.MimeType, m.SizeBytes, m.IsPrimary,
	).Scan(&m.CreatedAt)
}

func (r *MediaRepo) ByCaseID(ctx context.Context, caseID uuid.UUID) ([]models.Media, error) {
	rows, err := r.db.Query(ctx, `
		SELECT id, case_id, url, thumb_url, mime_type, size_bytes, is_primary, created_at
		FROM media WHERE case_id = $1 ORDER BY is_primary DESC, created_at ASC`, caseID)
	if err != nil {
		return nil, fmt.Errorf("media by case id: %w", err)
	}
	defer rows.Close()

	var media []models.Media
	for rows.Next() {
		var m models.Media
		if err := rows.Scan(
			&m.ID, &m.CaseID, &m.URL, &m.ThumbURL, &m.MimeType, &m.SizeBytes, &m.IsPrimary, &m.CreatedAt,
		); err != nil {
			return nil, fmt.Errorf("scan media: %w", err)
		}
		media = append(media, m)
	}
	return media, rows.Err()
}

// ── User Repository ───────────────────────────────────────────────────────────

type UserRepo struct{ db *pgxpool.Pool }

func NewUserRepo(db *pgxpool.Pool) *UserRepo { return &UserRepo{db: db} }

func (r *UserRepo) Create(ctx context.Context, u *models.User) error {
	u.ID = uuid.New()
	return r.db.QueryRow(ctx, `
		INSERT INTO users (id, email, phone, full_name, role, county, password_hash, is_verified, is_active, created_at, updated_at)
		VALUES ($1,$2,$3,$4,$5,$6,$7,$8,TRUE,NOW(),NOW())
		RETURNING created_at`,
		u.ID, u.Email, u.Phone, u.FullName, u.Role, u.County, u.PasswordHash, u.IsVerified,
	).Scan(&u.CreatedAt)
}

func (r *UserRepo) GetByEmail(ctx context.Context, email string) (*models.User, error) {
	var u models.User
	err := r.db.QueryRow(ctx, `
		SELECT id, email, phone, full_name, role, county, password_hash, is_verified, is_active, last_login_at, created_at, updated_at
		FROM users WHERE email = $1 AND is_active = TRUE`, email,
	).Scan(
		&u.ID, &u.Email, &u.Phone, &u.FullName, &u.Role, &u.County,
		&u.PasswordHash, &u.IsVerified, &u.IsActive, &u.LastLoginAt, &u.CreatedAt, &u.UpdatedAt,
	)
	if errors.Is(err, pgx.ErrNoRows) {
		return nil, ErrNotFound
	}
	return &u, err
}

func (r *UserRepo) UpdateLastLogin(ctx context.Context, id uuid.UUID) error {
	_, err := r.db.Exec(ctx, `UPDATE users SET last_login_at = NOW() WHERE id = $1`, id)
	return err
}

// ── Broadcast Repository ──────────────────────────────────────────────────────

type BroadcastRepo struct{ db *pgxpool.Pool }

func NewBroadcastRepo(db *pgxpool.Pool) *BroadcastRepo { return &BroadcastRepo{db: db} }

func (r *BroadcastRepo) Insert(ctx context.Context, b *models.BroadcastRecord) error {
	b.ID = uuid.New()
	_, err := r.db.Exec(ctx, `
		INSERT INTO broadcast_records (id, case_id, channel, recipient, message_id, status, sent_at)
		VALUES ($1,$2,$3,$4,$5,$6,NOW())`,
		b.ID, b.CaseID, b.Channel, b.Recipient, b.MessageID, b.Status,
	)
	return err
}

func (r *BroadcastRepo) MarkDelivered(ctx context.Context, messageID string) error {
	_, err := r.db.Exec(ctx,
		`UPDATE broadcast_records SET status='delivered', delivered_at=NOW() WHERE message_id=$1`,
		messageID,
	)
	return err
}

// ── CaseFilter ────────────────────────────────────────────────────────────────

// CaseFilter holds query parameters for listing cases.
// It intentionally has NO toWhereClause() method — filters are now applied
// via parameterised arguments in ListGeoPoints, never via string interpolation.
type CaseFilter struct {
	Status *models.CaseStatus
	County string
	Limit  int
	Offset int
}

// ── Sentinel errors ───────────────────────────────────────────────────────────

var ErrNotFound = errors.New("record not found")