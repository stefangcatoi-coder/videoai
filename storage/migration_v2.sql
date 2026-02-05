-- Migration to support long-form landscape videos and async pipeline
ALTER TABLE videos ADD COLUMN video_type TEXT DEFAULT 'short';
ALTER TABLE videos ADD COLUMN subtitle_color TEXT DEFAULT 'white';
ALTER TABLE videos ADD COLUMN segments_json TEXT;
ALTER TABLE videos ADD COLUMN assets_json TEXT;
