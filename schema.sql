-- MTHIX schema (SQLite). Money is stored as INTEGER Rwandan francs. Timestamps are UTC text.
PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    email         TEXT NOT NULL UNIQUE,
    phone         TEXT,
    password_hash TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended','banned')),
    kyc_status    TEXT NOT NULL DEFAULT 'none'   CHECK (kyc_status IN ('none','pending','verified','rejected')),
    ref_code      TEXT UNIQUE,
    failed_logins INTEGER NOT NULL DEFAULT 0,
    locked_until  TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE user_roles (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role    TEXT NOT NULL CHECK (role IN ('buyer','seller','reseller','affiliate','delivery','admin','finance_admin','support_admin')),
    PRIMARY KEY (user_id, role)
);

CREATE TABLE kyc_records (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL REFERENCES users(id),
    full_name    TEXT NOT NULL,
    doc_type     TEXT NOT NULL,
    doc_number   TEXT NOT NULL,
    business_tin TEXT,
    payout_account TEXT,
    status       TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','verified','rejected')),
    note         TEXT,
    reviewed_by  INTEGER REFERENCES users(id),
    reviewed_at  TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE stores (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL UNIQUE REFERENCES users(id),
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    description TEXT,
    status      TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE categories (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    name  TEXT NOT NULL,
    slug  TEXT NOT NULL UNIQUE,
    sort  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE products (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    store_id          INTEGER NOT NULL REFERENCES stores(id),
    category_id       INTEGER NOT NULL REFERENCES categories(id),
    name              TEXT NOT NULL,
    slug              TEXT NOT NULL UNIQUE,
    description       TEXT,
    brand             TEXT,
    base_price        INTEGER NOT NULL CHECK (base_price >= 0),
    compare_price     INTEGER,
    cost_price        INTEGER NOT NULL DEFAULT 0,
    image             TEXT,
    status            TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','suspended','archived')),
    reseller_eligible INTEGER NOT NULL DEFAULT 1,
    views             INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_products_store ON products(store_id);
CREATE INDEX idx_products_cat   ON products(category_id, status);

CREATE TABLE variants (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id      INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    sku             TEXT NOT NULL UNIQUE,
    barcode         TEXT,
    color           TEXT,
    size            TEXT,
    price           INTEGER NOT NULL CHECK (price >= 0),
    compare_price   INTEGER,
    cost_price      INTEGER NOT NULL DEFAULT 0,
    stock           INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    low_stock_alert INTEGER NOT NULL DEFAULT 5,
    active          INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX idx_variants_product ON variants(product_id);

CREATE TABLE inventory_movements (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    variant_id INTEGER NOT NULL REFERENCES variants(id),
    delta      INTEGER NOT NULL,
    reason     TEXT NOT NULL,            -- initial, restock, adjustment, reserve, release
    order_id   INTEGER,
    actor_id   INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE wishlist (
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, product_id)
);

CREATE TABLE orders (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT NOT NULL UNIQUE,
    group_code      TEXT NOT NULL,                 -- one checkout can create one order per store
    buyer_id        INTEGER NOT NULL REFERENCES users(id),
    store_id        INTEGER NOT NULL REFERENCES stores(id),
    status          TEXT NOT NULL DEFAULT 'pending_payment' CHECK (status IN
                    ('pending_payment','paid','confirmed','packed','shipped','out_for_delivery','delivered','completed','cancelled','refunded')),
    subtotal        INTEGER NOT NULL,              -- product amount (basis for commissions)
    delivery_fee    INTEGER NOT NULL DEFAULT 0,    -- tracked separately from product settlement
    total           INTEGER NOT NULL,
    delivery_method TEXT NOT NULL,
    delivery_code   TEXT NOT NULL,                 -- buyer gives this to the courier as proof of delivery
    ship_name       TEXT NOT NULL,
    ship_phone      TEXT NOT NULL,
    ship_address    TEXT NOT NULL,
    reseller_id     INTEGER REFERENCES users(id),
    affiliate_id    INTEGER REFERENCES users(id),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    paid_at         TEXT,
    delivered_at    TEXT,
    completed_at    TEXT
);
CREATE INDEX idx_orders_buyer  ON orders(buyer_id);
CREATE INDEX idx_orders_store  ON orders(store_id, status);
CREATE INDEX idx_orders_group  ON orders(group_code);

CREATE TABLE order_items (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id      INTEGER NOT NULL REFERENCES orders(id),
    variant_id    INTEGER NOT NULL REFERENCES variants(id),
    product_id    INTEGER NOT NULL REFERENCES products(id),
    product_name  TEXT NOT NULL,
    variant_label TEXT,
    unit_price    INTEGER NOT NULL,
    unit_cost     INTEGER NOT NULL DEFAULT 0,
    qty           INTEGER NOT NULL CHECK (qty > 0),
    line_total    INTEGER NOT NULL
);

CREATE TABLE order_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL REFERENCES orders(id),
    from_status TEXT,
    to_status   TEXT NOT NULL,
    actor_id    INTEGER,
    actor_kind  TEXT NOT NULL,                      -- buyer, seller, delivery, admin, system
    reason      TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE payments (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id     INTEGER NOT NULL REFERENCES orders(id),
    method       TEXT NOT NULL,
    amount       INTEGER NOT NULL,
    external_ref TEXT NOT NULL UNIQUE,              -- gateway reference; uniqueness gives webhook idempotency
    internal_txn TEXT NOT NULL UNIQUE,
    status       TEXT NOT NULL DEFAULT 'initiated' CHECK (status IN ('initiated','success','failed','refunded')),
    recon_status TEXT NOT NULL DEFAULT 'unreconciled' CHECK (recon_status IN ('unreconciled','reconciled','mismatch')),
    payload      TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    paid_at      TEXT
);

CREATE TABLE escrow (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL UNIQUE REFERENCES orders(id),
    amount      INTEGER NOT NULL,
    status      TEXT NOT NULL DEFAULT 'held' CHECK (status IN ('held','released','refunded')),
    held_at     TEXT NOT NULL DEFAULT (datetime('now')),
    released_at TEXT
);

CREATE TABLE commission_rules (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    version                 INTEGER NOT NULL UNIQUE,
    platform_bps            INTEGER NOT NULL,        -- basis points: 300 = 3%
    reseller_bps            INTEGER NOT NULL,
    affiliate_bps           INTEGER NOT NULL,
    low_profit_threshold    INTEGER NOT NULL,        -- RWF
    low_profit_platform_bps INTEGER NOT NULL,
    effective_from          TEXT NOT NULL,
    created_by              INTEGER REFERENCES users(id),
    created_at              TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE commissions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id     INTEGER NOT NULL REFERENCES orders(id),
    party        TEXT NOT NULL CHECK (party IN ('seller','reseller','affiliate','platform')),
    user_id      INTEGER NOT NULL REFERENCES users(id),
    rate_bps     INTEGER NOT NULL,
    amount       INTEGER NOT NULL,
    rule_version INTEGER NOT NULL,
    status       TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','available','reversed')),
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (order_id, party)
);

-- The wallet balance is never stored; it is the sum of immutable ledger entries.
CREATE TABLE ledger_entries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    order_id   INTEGER REFERENCES orders(id),
    type       TEXT NOT NULL,
    bucket     TEXT NOT NULL CHECK (bucket IN ('pending','available')),
    amount     INTEGER NOT NULL,                     -- signed
    ref        TEXT,
    note       TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_ledger_user  ON ledger_entries(user_id, bucket);
CREATE INDEX idx_ledger_order ON ledger_entries(order_id);
CREATE TRIGGER ledger_no_update BEFORE UPDATE ON ledger_entries BEGIN SELECT RAISE(ABORT, 'ledger entries are immutable'); END;
CREATE TRIGGER ledger_no_delete BEFORE DELETE ON ledger_entries BEGIN SELECT RAISE(ABORT, 'ledger entries are immutable'); END;

CREATE TABLE withdrawals (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL REFERENCES users(id),
    amount      INTEGER NOT NULL CHECK (amount > 0),
    method      TEXT NOT NULL,
    account     TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'requested' CHECK (status IN ('requested','review','approved','processing','paid','rejected')),
    risk_flags  TEXT,
    idem_key    TEXT NOT NULL UNIQUE,                -- a double-click cannot create two withdrawals
    reviewed_by INTEGER REFERENCES users(id),
    reviewed_at TEXT,
    note        TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    paid_at     TEXT
);

CREATE TABLE delivery_jobs (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id      INTEGER NOT NULL UNIQUE REFERENCES orders(id),
    partner_id    INTEGER REFERENCES users(id),
    status        TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','accepted','picked_up','out_for_delivery','delivered','failed')),
    fee           INTEGER NOT NULL,
    failed_reason TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE referral_clicks (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    kind       TEXT NOT NULL CHECK (kind IN ('reseller','affiliate')),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    product_id INTEGER REFERENCES products(id),
    ip_hash    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE reviews (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products(id),
    user_id    INTEGER NOT NULL REFERENCES users(id),
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    rating     INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE (product_id, user_id, order_id)           -- reviews only exist for real, completed orders
);

CREATE TABLE disputes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id    INTEGER NOT NULL REFERENCES orders(id),
    opened_by   INTEGER NOT NULL REFERENCES users(id),
    reason      TEXT NOT NULL,
    status      TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','resolved')),
    resolution  TEXT,
    resolved_by INTEGER REFERENCES users(id),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    resolved_at TEXT
);

CREATE TABLE notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    event      TEXT NOT NULL,
    title      TEXT NOT NULL,
    body       TEXT,
    link       TEXT,
    read_at    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_notif_user ON notifications(user_id, read_at);

CREATE TABLE messages (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    from_id    INTEGER NOT NULL REFERENCES users(id),
    to_id      INTEGER NOT NULL REFERENCES users(id),
    body       TEXT NOT NULL,
    read_at    TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_msg_pair ON messages(from_id, to_id);

CREATE TABLE audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_id   INTEGER,
    action     TEXT NOT NULL,
    entity     TEXT,
    entity_id  INTEGER,
    detail     TEXT,
    ip         TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TRIGGER audit_no_update BEFORE UPDATE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit log is append-only'); END;
CREATE TRIGGER audit_no_delete BEFORE DELETE ON audit_log BEGIN SELECT RAISE(ABORT, 'audit log is append-only'); END;
