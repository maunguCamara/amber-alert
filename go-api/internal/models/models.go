package models

import (
	"time"

	"github.com/google/uuid"
)

// ─── Enums ───────────────────────────────────────────────────────────────────

type CaseStatus string

const (
	CaseStatusActive   CaseStatus = "active"
	CaseStatusReview   CaseStatus = "review"
	CaseStatusResolved CaseStatus = "resolved"
	CaseStatusClosed   CaseStatus = "closed"
)

type Gender string

const (
	GenderMale    Gender = "male"
	GenderFemale  Gender = "female"
	GenderUnknown Gender = "unknown"
)

type UserRole string

const (
	RolePublic   UserRole = "public"    // general member of public
	RoleOfficer  UserRole = "officer"   // police / county officer
	RoleAdmin    UserRole = "admin"     // national admin
	RoleSuperAdmin UserRole = "superadmin"
)

type BroadcastChannel string

const (
	ChannelSMS      BroadcastChannel = "sms"
	ChannelWhatsApp BroadcastChannel = "whatsapp"
	ChannelEmail    BroadcastChannel = "email"
	ChannelWebPush  BroadcastChannel = "webpush"
)

// ─── Case ────────────────────────────────────────────────────────────────────

// Case represents a missing child alert.
type Case struct {
	ID          uuid.UUID  `db:"id"           json:"id"`
	ReferenceNo string     `db:"reference_no" json:"reference_no"` // e.g. KE-2024-00042

	// Child details
	ChildName   string  `db:"child_name"   json:"child_name"`
	Age         int     `db:"age"          json:"age"`
	Gender      Gender  `db:"gender"       json:"gender"`
	HeightCM    float64 `db:"height_cm"    json:"height_cm"`
	WeightKG    float64 `db:"weight_kg"    json:"weight_kg,omitempty"`
	Complexion  string  `db:"complexion"   json:"complexion,omitempty"`
	Clothing    string  `db:"clothing"     json:"clothing"`
	Distinctive string  `db:"distinctive"  json:"distinctive,omitempty"` // scars, birthmarks
	Languages   []string `db:"languages"   json:"languages,omitempty"`    // ["Swahili","Luo"]

	// Location (PostGIS)
	LastSeenArea     string  `db:"last_seen_area"     json:"last_seen_area"`
	County           string  `db:"county"             json:"county"`
	SubCounty        string  `db:"sub_county"         json:"sub_county,omitempty"`
	LastSeenLat      float64 `db:"last_seen_lat"      json:"last_seen_lat"`
	LastSeenLng      float64 `db:"last_seen_lng"      json:"last_seen_lng"`

	// Circumstances
	Description     string    `db:"description"      json:"description"`
	MissingSince    time.Time `db:"missing_since"    json:"missing_since"`
	CircumstanceType string   `db:"circumstance_type" json:"circumstance_type"` // wandered/abducted/unknown

	// Status
	Status      CaseStatus `db:"status"       json:"status"`
	ResolvedAt  *time.Time `db:"resolved_at"  json:"resolved_at,omitempty"`
	Resolution  string     `db:"resolution"   json:"resolution,omitempty"`

	// Reporter
	ReporterID   uuid.UUID `db:"reporter_id"   json:"reporter_id"`
	ReporterType string    `db:"reporter_type" json:"reporter_type"` // public/police/school
	ContactPhone string    `db:"contact_phone" json:"contact_phone,omitempty"`

	// Media
	Photos []Media `db:"-" json:"photos,omitempty"`

	// Audit
	CreatedAt time.Time  `db:"created_at" json:"created_at"`
	UpdatedAt time.Time  `db:"updated_at" json:"updated_at"`
	CreatedBy uuid.UUID  `db:"created_by" json:"created_by"`
	UpdatedBy *uuid.UUID `db:"updated_by" json:"updated_by,omitempty"`
}

// CaseGeoPoint is the lightweight struct returned for map rendering.
type CaseGeoPoint struct {
	ID          uuid.UUID  `json:"id"`
	ReferenceNo string     `json:"reference_no"`
	ChildName   string     `json:"child_name"`
	Age         int        `json:"age"`
	Gender      Gender     `json:"gender"`
	Status      CaseStatus `json:"status"`
	County      string     `json:"county"`
	Lat         float64    `json:"lat"`
	Lng         float64    `json:"lng"`
	MissingSince time.Time `json:"missing_since"`
	ThumbnailURL string    `json:"thumbnail_url,omitempty"`
}

// ─── Media ───────────────────────────────────────────────────────────────────

type Media struct {
	ID        uuid.UUID `db:"id"         json:"id"`
	CaseID    uuid.UUID `db:"case_id"    json:"case_id"`
	URL       string    `db:"url"        json:"url"`
	ThumbURL  string    `db:"thumb_url"  json:"thumb_url,omitempty"`
	MimeType  string    `db:"mime_type"  json:"mime_type"`
	SizeBytes int64     `db:"size_bytes" json:"size_bytes"`
	IsPrimary bool      `db:"is_primary" json:"is_primary"`
	CreatedAt time.Time `db:"created_at" json:"created_at"`
}

// ─── User ────────────────────────────────────────────────────────────────────

type User struct {
	ID           uuid.UUID `db:"id"            json:"id"`
	Email        string    `db:"email"         json:"email"`
	Phone        string    `db:"phone"         json:"phone,omitempty"`
	FullName     string    `db:"full_name"     json:"full_name"`
	Role         UserRole  `db:"role"          json:"role"`
	County       string    `db:"county"        json:"county,omitempty"` // assigned county for officers
	PasswordHash string    `db:"password_hash" json:"-"`
	IsVerified   bool      `db:"is_verified"   json:"is_verified"`
	IsActive     bool      `db:"is_active"     json:"is_active"`
	LastLoginAt  *time.Time `db:"last_login_at" json:"last_login_at,omitempty"`
	CreatedAt    time.Time `db:"created_at"    json:"created_at"`
	UpdatedAt    time.Time `db:"updated_at"    json:"updated_at"`
}

// ─── Broadcast record ────────────────────────────────────────────────────────

type BroadcastRecord struct {
	ID          uuid.UUID        `db:"id"           json:"id"`
	CaseID      uuid.UUID        `db:"case_id"      json:"case_id"`
	Channel     BroadcastChannel `db:"channel"      json:"channel"`
	Recipient   string           `db:"recipient"    json:"recipient"`  // phone or email
	MessageID   string           `db:"message_id"   json:"message_id"` // provider message ID
	Status      string           `db:"status"       json:"status"`     // sent/delivered/failed
	SentAt      time.Time        `db:"sent_at"      json:"sent_at"`
	DeliveredAt *time.Time       `db:"delivered_at" json:"delivered_at,omitempty"`
}

// ─── WebSocket event ─────────────────────────────────────────────────────────

type WSEvent struct {
	Type    string `json:"type"`    // case.new | case.updated | case.resolved
	Payload any    `json:"payload"`
}