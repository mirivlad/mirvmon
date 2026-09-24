CREATE TABLE public_status_items (
    id BIGSERIAL PRIMARY KEY,
    server_id BIGINT REFERENCES servers(id) ON DELETE CASCADE,
    website_id BIGINT REFERENCES websites(id) ON DELETE CASCADE,
    display_name VARCHAR(100) NOT NULL CHECK (length(trim(display_name)) > 0),
    sort_order INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT public_status_items_source_check CHECK (
        (server_id IS NOT NULL)::integer + (website_id IS NOT NULL)::integer = 1
    )
);

CREATE UNIQUE INDEX public_status_items_server_idx
    ON public_status_items(server_id) WHERE server_id IS NOT NULL;
CREATE UNIQUE INDEX public_status_items_website_idx
    ON public_status_items(website_id) WHERE website_id IS NOT NULL;
