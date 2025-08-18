-- Utilisateurs
CREATE TABLE app_user (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email VARCHAR(180) NOT NULL UNIQUE,
  "password" VARCHAR(255) NOT NULL,
  "fullName" VARCHAR(150),
  phone VARCHAR(40),
  roles JSONB NOT NULL DEFAULT '[]'::jsonb,
  "language" VARCHAR(8) DEFAULT 'fr',
  "isActive" BOOLEAN NOT NULL DEFAULT TRUE,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Organisateurs (profil rattaché à un user)
CREATE TABLE organizer (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(180),
  phone VARCHAR(40),
  website VARCHAR(255),
  "isVerified" BOOLEAN NOT NULL DEFAULT FALSE,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Catégories (avec couleur et slug)
CREATE TABLE category (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL UNIQUE,
  color VARCHAR(16),
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Lieux (pour recherche par géo)
CREATE TABLE venue (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  address1 VARCHAR(255),
  address2 VARCHAR(255),
  city VARCHAR(120),
  country VARCHAR(2), -- ISO 3166-1 alpha-2
  latitude NUMERIC(9,6),
  longitude NUMERIC(9,6),
  capacity INTEGER,
  timezone VARCHAR(50),
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Événements
CREATE TABLE event (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  organizer_id INTEGER NOT NULL REFERENCES organizer(id) ON DELETE CASCADE,
  category_id INTEGER REFERENCES category(id) ON DELETE SET NULL,
  venue_id INTEGER REFERENCES venue(id) ON DELETE SET NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  "startedAt" TIMESTAMPTZ NOT NULL,
  "endAt"     TIMESTAMPTZ NOT NULL,
  "imageUrl"  VARCHAR(255),
  status VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft|pending|published|cancelled|archived
  visibility VARCHAR(20) NOT NULL DEFAULT 'public', -- public|private|unlisted
  capacity INTEGER,              -- plafond global optionnel
  currency VARCHAR(3) DEFAULT 'EUR',
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Types de billets (tarifs)
CREATE TABLE ticket_type (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_id INTEGER NOT NULL REFERENCES event(id) ON DELETE CASCADE,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  prix NUMERIC(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
  quantite_max INTEGER NOT NULL,                -- stock total pour ce type
  "perUserLimit" INTEGER,                       -- limite par acheteur
  "salesStartAt" TIMESTAMPTZ,
  "salesEndAt"   TIMESTAMPTZ,
  "isActive" BOOLEAN NOT NULL DEFAULT TRUE,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Commandes
CREATE TABLE "order" (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
  event_id INTEGER NOT NULL REFERENCES event(id) ON DELETE CASCADE,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|paid|cancelled|refunded|expired
  "amountSubtotal" NUMERIC(10,2) NOT NULL DEFAULT 0,
  "amountFees"     NUMERIC(10,2) NOT NULL DEFAULT 0,
  "amountTotal"    NUMERIC(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
  "couponCode" VARCHAR(80),
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Lignes de commande (répartition par type de billet)
CREATE TABLE order_item (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  order_id INTEGER NOT NULL REFERENCES "order"(id) ON DELETE CASCADE,
  ticket_type_id INTEGER NOT NULL REFERENCES ticket_type(id) ON DELETE RESTRICT,
  quantity INTEGER NOT NULL CHECK (quantity > 0),
  "unitPrice" NUMERIC(10,2) NOT NULL,
  "totalPrice" NUMERIC(10,2) NOT NULL,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Tickets (un ticket = 1 QR = 1 entrée)
CREATE TABLE ticket (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),      -- pgcrypto
  order_item_id INTEGER NOT NULL REFERENCES order_item(id) ON DELETE CASCADE,
  ticket_type_id INTEGER NOT NULL REFERENCES ticket_type(id) ON DELETE RESTRICT,
  buyer_id INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
  "attendeeName"  VARCHAR(150),
  "attendeeEmail" VARCHAR(180),
  "qrSecret"  VARCHAR(64) NOT NULL,                   -- valeur hashable pour vérifier
  "qrImageUrl" VARCHAR(255),                          -- stockage image si générée
  status VARCHAR(20) NOT NULL DEFAULT 'valid',        -- valid|used|cancelled|refunded
  "usedAt" TIMESTAMPTZ,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Scans de tickets (antifraude / journal)
CREATE TABLE ticket_scan (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  ticket_id UUID NOT NULL REFERENCES ticket(id) ON DELETE CASCADE,
  scanned_by INTEGER REFERENCES app_user(id) ON DELETE SET NULL,
  "scannedAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  result VARCHAR(20) NOT NULL, -- valid|already_used|invalid|cancelled
  device VARCHAR(120),
  latitude NUMERIC(9,6),
  longitude NUMERIC(9,6)
);

-- Paiements
CREATE TABLE payment (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  order_id INTEGER NOT NULL REFERENCES "order"(id) ON DELETE CASCADE,
  provider VARCHAR(40) NOT NULL,          -- stripe, mobile_money, ...
  method   VARCHAR(40),                   -- card, momo, ...
  status   VARCHAR(20) NOT NULL,          -- requires_action|succeeded|failed|refunded|cancelled
  amount   NUMERIC(10,2) NOT NULL,
  currency VARCHAR(3) NOT NULL,
  "providerPaymentIntentId" VARCHAR(120), -- Stripe payment_intent id, etc.
  "providerChargeId"        VARCHAR(120),
  "failureCode" VARCHAR(80),
  "failureMessage" TEXT,
  payload JSONB,                          -- réponse brute du PSP
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  "updatedAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Remboursements éventuels
CREATE TABLE refund (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  payment_id INTEGER NOT NULL REFERENCES payment(id) ON DELETE CASCADE,
  amount NUMERIC(10,2) NOT NULL,
  status VARCHAR(20) NOT NULL,            -- pending|succeeded|failed
  "providerRefundId" VARCHAR(120),
  payload JSONB,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Modération / validation des événements (par des admins)
CREATE TABLE event_moderation (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_id INTEGER NOT NULL REFERENCES event(id) ON DELETE CASCADE,
  status VARCHAR(20) NOT NULL,            -- pending|approved|rejected
  reason TEXT,
  reviewed_by INTEGER REFERENCES app_user(id),
  "reviewedAt" TIMESTAMPTZ
);

-- Médias d’un événement (affiche, galerie, vidéos, etc.)
CREATE TABLE media_asset (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_id INTEGER NOT NULL REFERENCES event(id) ON DELETE CASCADE,
  type VARCHAR(20) NOT NULL,              -- image|video|flyer|other
  url  VARCHAR(255) NOT NULL,
  position INTEGER DEFAULT 0,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Favoris (pour suggestions/notifications)
CREATE TABLE favorite_event (
  user_id INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
  event_id INTEGER NOT NULL REFERENCES event(id) ON DELETE CASCADE,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (user_id, event_id)
);

-- Codes promo (au niveau event/organizer)
CREATE TABLE promo_code (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  "discountType" VARCHAR(10) NOT NULL,    -- percent|fixed
  "amountOff" NUMERIC(10,2),
  currency VARCHAR(3),
  "maxRedemptions" INTEGER,
  "redeemedCount" INTEGER NOT NULL DEFAULT 0,
  "validFrom" TIMESTAMPTZ,
  "validTo"   TIMESTAMPTZ,
  event_id INTEGER REFERENCES event(id) ON DELETE CASCADE,
  organizer_id INTEGER REFERENCES organizer(id) ON DELETE CASCADE,
  "isActive" BOOLEAN NOT NULL DEFAULT TRUE,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Appareils & notifications (push)
CREATE TABLE device (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
  "fcmToken" TEXT NOT NULL UNIQUE,
  platform VARCHAR(20),                   -- android|ios|web
  "lastSeenAt" TIMESTAMPTZ,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE notification_preference (
  user_id INTEGER PRIMARY KEY REFERENCES app_user(id) ON DELETE CASCADE,
  "pushEnabled" BOOLEAN NOT NULL DEFAULT TRUE,
  "emailEnabled" BOOLEAN NOT NULL DEFAULT TRUE,
  "marketingOptin" BOOLEAN NOT NULL DEFAULT FALSE,
  "locale" VARCHAR(8) DEFAULT 'fr'
);

CREATE TABLE notification (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES app_user(id) ON DELETE CASCADE,
  title VARCHAR(200),
  body TEXT,
  type VARCHAR(40),                       -- reminder|promo|system...
  data JSONB,
  channel VARCHAR(20) NOT NULL DEFAULT 'push', -- push|email
  "sentAt" TIMESTAMPTZ,
  "deliveredAt" TIMESTAMPTZ,
  "readAt" TIMESTAMPTZ
);

-- Journal des webhooks Stripe (sécurité & reprise)
CREATE TABLE webhook_event (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  provider VARCHAR(40) NOT NULL,          -- stripe
  "providerEventId" VARCHAR(120) NOT NULL UNIQUE,
  type VARCHAR(120),
  payload JSONB NOT NULL,
  processed BOOLEAN NOT NULL DEFAULT FALSE,
  "createdAt" TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- i18n : traductions (événements et catégories)
CREATE TABLE event_translation (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  event_id INTEGER NOT NULL REFERENCES event(id) ON DELETE CASCADE,
  locale VARCHAR(8) NOT NULL,             -- fr, en, ...
  title VARCHAR(200),
  description TEXT,
  UNIQUE (event_id, locale)
);

CREATE TABLE category_translation (
  id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  category_id INTEGER NOT NULL REFERENCES category(id) ON DELETE CASCADE,
  locale VARCHAR(8) NOT NULL,
  name VARCHAR(100),
  UNIQUE (category_id, locale)
);
