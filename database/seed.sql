-- BacaKomik seed data
-- Default admin: admin@example.com / admin12345
INSERT INTO users (name, email, password_hash, role, status) VALUES
('Administrator', 'admin@example.com', '$2y$10$E1xN6Kx5vN7qB1CmKQk5/.QWXBcJj7y2v8kK0d5h8oXgbq8a3D5wG', 'admin', 'active');
-- Note: hash above corresponds to "admin12345" generated with password_hash(PASSWORD_BCRYPT)

INSERT INTO genres (name, slug) VALUES
('Action','action'),('Adventure','adventure'),('Comedy','comedy'),
('Drama','drama'),('Fantasy','fantasy'),('Romance','romance'),
('Horror','horror'),('School Life','school-life'),('Sci-Fi','sci-fi'),
('Slice of Life','slice-of-life'),('Supernatural','supernatural'),('Mystery','mystery');

INSERT INTO settings (setting_key, setting_value) VALUES
('site_name','BacaKomik'),
('site_logo',''),
('site_favicon',''),
('meta_title','BacaKomik - Baca Komik, Manga, Manhwa, Manhua Online'),
('meta_description','Baca komik, manga, manhwa dan manhua bahasa Indonesia gratis di BacaKomik.'),
('default_theme','light'),
('maintenance_mode','0'),
('allow_registration','1'),
('hero_layout','classic'),
('card_style','modern'),
('grid_style','default'),
('scraper_delay','1'),
('scraper_timeout','30'),
('scraper_user_agent','BacaKomikBot/1.0 (+https://bacakomik.local)'),
('scraper_concurrent','1'),
('scraper_whitelist','komiku.org,komiku.id,img.komiku.org'),
('scraper_use_api','1'),
('scraper_api_url','http://scraper:8080'),
('scraper_api_key','devtest123'),
('scraper_api_timeout','30'),
('scraper_remote_storage','1'),
('scraper_proxy_public','1'),
('comments_enabled','1'),
('comments_on_comic','1'),
('comments_on_chapter','1'),
('comments_api_url','');

INSERT INTO ad_slots (slot_key, slot_name, ad_code, is_active) VALUES
('home_top','Homepage Top','',1),
('home_bottom','Homepage Bottom','',1),
('detail_top','Manga Detail Top','',1),
('detail_bottom','Manga Detail Bottom','',1),
('reader_top','Reader Top','',1),
('reader_middle','Reader Middle','',1),
('reader_bottom','Reader Bottom','',1),
('sidebar','Sidebar','',1);

INSERT INTO pages (title, slug, content, status) VALUES
('About','about','<p>Tentang BacaKomik.</p>','published'),
('DMCA','dmca','<p>Kebijakan DMCA.</p>','published'),
('Privacy Policy','privacy-policy','<p>Kebijakan Privasi.</p>','published'),
('Contact','contact','<p>Hubungi kami.</p>','published'),
('Terms','terms','<p>Syarat & ketentuan.</p>','published');
