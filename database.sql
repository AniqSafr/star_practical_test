-- Import this file in phpMyAdmin (Import tab) or run: mysql -u root < database.sql

CREATE DATABASE IF NOT EXISTS moon_news
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE moon_news;

-- ------------------------------------------------------------
-- Admin users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Failed admin logins (used to rate-limit the login page: 5 failures / 15 minutes per IP).
-- Only a hash of the IP address is stored.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash      CHAR(64)  NOT NULL,
  attempted_at DATETIME  NOT NULL,
  INDEX idx_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Website content (posts)
-- Dropped first so that re-importing this file resets the posts to the data below.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS posts;

CREATE TABLE posts (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(150)  NOT NULL,
  content    TEXT          NOT NULL,                 -- the "body" of the post
  tags       VARCHAR(255)  NOT NULL DEFAULT '',      -- comma separated, e.g. history,american,crime
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_posts_created (created_at, id)       -- the home page lists the newest posts first
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Social media platforms (the share buttons are built from this table)
--
-- To add a new platform later, just INSERT a row - no code change needed.
-- share_url_template placeholders (they are URL-encoded automatically):
--   {url}        the page URL
--   {title}      the page / post title
--   {desc}       the short description
--   {title_url}  title + space + URL     (for apps with a single text box, e.g. WhatsApp)
--   {desc_url}   description + blank line + URL   (e.g. an email body)
-- icon_svg: inline <svg viewBox="0 0 24 24" fill="currentColor"> ... </svg>  (admin-only content)
-- color: button background (hex).  opens_new_tab: 0 for mailto: links.
-- sort_order: lower number = shown first.  To remove a button, delete its row (old clicks are kept).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_platforms (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug               VARCHAR(50)   NOT NULL UNIQUE,        -- stored in share_clicks.platform
  name               VARCHAR(100)  NOT NULL,               -- shown in tooltips and the admin dashboard
  share_url_template VARCHAR(1024) NOT NULL,
  icon_svg           TEXT          NOT NULL,
  color              VARCHAR(9)    NOT NULL DEFAULT '#6b7785',
  opens_new_tab      TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order         INT           NOT NULL DEFAULT 0,
  created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- INSERT IGNORE: re-importing this file will not duplicate or overwrite rows you already have.
INSERT IGNORE INTO social_platforms
  (slug, name, share_url_template, icon_svg, color, opens_new_tab, sort_order) VALUES
  ('facebook', 'Facebook', 'https://www.facebook.com/sharer/sharer.php?u={url}', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>', '#1877f2', 1, 1),
  ('x', 'X', 'https://twitter.com/intent/tweet?url={url}&text={title}', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/></svg>', '#111111', 1, 2),
  ('whatsapp', 'WhatsApp', 'https://wa.me/?text={title_url}', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>', '#25a244', 1, 3),
  ('telegram', 'Telegram', 'https://t.me/share/url?url={url}&text={title}', '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>', '#229ed9', 1, 4),
  ('email', 'Email', 'mailto:?subject={title}&body={desc_url}', '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6.5L21 7"/></svg>', '#6b7785', 0, 5);

-- ------------------------------------------------------------
-- Share button click tracking (requirement 2)
-- One row per click: which page, which social button, when.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS share_clicks (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_url   VARCHAR(2048) NOT NULL,
  page_title VARCHAR(255)  NULL,                     -- post title at the time of the click
  platform   VARCHAR(50)   NOT NULL,                 -- = social_platforms.slug
  clicked_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_clicked_at       (clicked_at),            -- latest clicks, MIN(clicked_at)
  INDEX idx_clicked_platform (clicked_at, platform)   -- dashboard counts: the date range and the platform are both read from the index
) ENGINE=InnoDB;

-- Upgrading a database that already has share_clicks (run once; the old index only had the platform, which is not selective):
--   ALTER TABLE share_clicks DROP INDEX idx_platform, ADD INDEX idx_clicked_platform (clicked_at, platform);

-- If share_clicks already existed with the old ENUM column, convert it (keeps your rows):
ALTER TABLE share_clicks MODIFY platform VARCHAR(50) NOT NULL;

-- ------------------------------------------------------------
-- Default admin login:  admin / Admin@12345   (change it after your first login!)
-- ------------------------------------------------------------
INSERT IGNORE INTO admins (username, password_hash) VALUES
  ('admin', '$2y$10$Oa48nhLCijp6TaLr.0FGbuWJzxeBfKi0xgWrZs3frimcM3sLS5TRS');

-- ------------------------------------------------------------
-- Posts (30 rows)
-- ------------------------------------------------------------
INSERT INTO posts (id, title, content, tags) VALUES
  (1, 'His mother had always taught him', 'His mother had always taught him not to ever think of himself as better than others. He''d tried to live by this motto. He never looked down on those who were less fortunate or who had less money than him. But the stupidity of the group of people he was talking to made him change his mind.', 'history,american,crime'),
  (2, 'He was an expert but not in a discipline', 'He was an expert but not in a discipline that anyone could fully appreciate. He knew how to hold the cone just right so that the soft server ice-cream fell into it at the precise angle to form a perfect cone each and every time. It had taken years to perfect and he could now do it without even putting any thought behind it.', 'french,fiction,english'),
  (3, 'Dave watched as the forest burned up on the hill.', 'Dave watched as the forest burned up on the hill, only a few miles from her house. The car had been hastily packed and Marta was inside trying to round up the last of the pets. Dave went through his mental list of the most important papers and documents that they couldn''t leave behind. He scolded himself for not having prepared these better in advance and hoped that he had remembered everything that was needed. He continued to wait for Marta to appear with the pets, but she still was nowhere to be seen.', 'magical,history,french'),
  (4, 'All he wanted was a candy bar.', 'All he wanted was a candy bar. It didn''t seem like a difficult request to comprehend, but the clerk remained frozen and didn''t seem to want to honor the request. It might have had something to do with the gun pointed at his face.', 'mystery,english,american'),
  (5, 'Hopes and dreams were dashed that day.', 'Hopes and dreams were dashed that day. It should have been expected, but it still came as a shock. The warning signs had been ignored in favor of the possibility, however remote, that it could actually happen. That possibility had grown from hope to an undeniable belief it must be destiny. That was until it wasn''t and the hopes and dreams came crashing down.', 'crime,mystery,love'),
  (6, 'Dave wasn''t exactly sure how he had ended up', 'Dave wasn''t exactly sure how he had ended up in this predicament. He ran through all the events that had lead to this current situation and it still didn''t make sense. He wanted to spend some time to try and make sense of it all, but he had higher priorities at the moment. The first was how to get out of his current situation of being naked in a tree with snow falling all around and no way for him to get down.', 'english,classic,american'),
  (7, 'This is important to remember.', 'This is important to remember. Love isn''t like pie. You don''t need to divide it among all your friends and loved ones. No matter how much love you give, you can always give more. It doesn''t run out, so don''t try to hold back giving it as if it may one day run out. Give it freely and as much as you want.', 'magical,crime'),
  (8, 'One can cook on and with an open fire.', 'One can cook on and with an open fire. These are some of the ways to cook with fire outside. Cooking meat using a spit is a great way to evenly cook meat. In order to keep meat from burning, it''s best to slowly rotate it.', 'american,english'),
  (9, 'There are different types of secrets.', 'There are different types of secrets. She had held onto plenty of them during her life, but this one was different. She found herself holding onto the worst type. It was the type of secret that could gnaw away at your insides if you didn''t tell someone about it, but it could end up getting you killed if you did.', 'american,history,magical'),
  (10, 'They rushed out the door.', 'They rushed out the door, grabbing anything and everything they could think of they might need. There was no time to double-check to make sure they weren''t leaving something important behind. Everything was thrown into the car and they sped off. Thirty minutes later they were safe and that was when it dawned on them that they had forgotten the most important thing of all.', 'fiction,magical,history'),
  (11, 'It wasn''t quite yet time to panic.', 'It wasn''t quite yet time to panic. There was still time to salvage the situation. At least that is what she was telling himself. The reality was that it was time to panic and there wasn''t time to salvage the situation, but he continued to delude himself into believing there was.', 'mystery,american,history'),
  (12, 'She was aware that things could go wrong.', 'She was aware that things could go wrong. In fact, she had trained her entire life in anticipation that things would go wrong one day. She had quiet confidence as she started to see that this was the day that all her training would be worthwhile and useful. At this point, she had no idea just how wrong everything would go that day.', 'love,english'),
  (13, 'She wanted rainbow hair.', 'She wanted rainbow hair. That''s what she told the hairdresser. It should be deep rainbow colors, too. She wasn''t interested in pastel rainbow hair. She wanted it deep and vibrant so there was no doubt that she had done this on purpose.', 'mystery,classic,french'),
  (14, 'The paper was blank.', 'The paper was blank. It shouldn''t have been. There should have been writing on the paper, at least a paragraph if not more. The fact that the writing wasn''t there was frustrating. Actually, it was even more than frustrating. It was downright distressing.', 'mystery,english,love'),
  (15, 'The trees, therefore, must be such old', 'The trees, therefore, must be such old and primitive techniques that they thought nothing of them, deeming them so inconsequential that even savages like us would know of them and not be suspicious. At that, they probably didn''t have too much time after they detected us orbiting and intending to land. And if that were true, there could be only one place where their civilization was hidden.', 'fiction,history,crime'),
  (16, 'There was only one way to do things in the Statton house.', 'There was only one way to do things in the Statton house. That one way was to do exactly what the father, Charlie, demanded. He made the decisions and everyone else followed without question. That was until today.', 'magical,french,american'),
  (17, 'She was in a hurry.', 'She was in a hurry. Not the standard hurry when you''re in a rush to get someplace, but a frantic hurry. The type of hurry where a few seconds could mean life or death. She raced down the road ignoring speed limits and weaving between cars. She was only a few minutes away when traffic came to a dead standstill on the road ahead.', 'french,magical,english'),
  (18, 'She had a terrible habit o comparing her life to others', 'She had a terrible habit o comparing her life to others. She realized that their life experiences were completely different than her own and that she saw only what they wanted her to see, but that didn''t matter. She still compared herself and yearned for what she thought they had and she didn''t.', 'history,french,love'),
  (19, 'The rain and wind abruptly stopped.', 'The rain and wind abruptly stopped, but the sky still had the gray swirls of storms in the distance. Dave knew this feeling all too well. The calm before the storm. He only had a limited amount of time before all Hell broke loose, but he stopped to admire the calmness. Maybe it would be different this time, he thought, with the knowledge deep within that it wouldn''t.', 'fiction,crime,magical'),
  (20, 'He couldn''t remember exactly where he had read it', 'He couldn''t remember exactly where he had read it, but he was sure that he had. The fact that she didn''t believe him was quite frustrating as he began to search the Internet to find the article. It wasn''t as if it was something that seemed impossible. Yet she insisted on always seeing the source whenever he stated a fact.', 'french,classic'),
  (21, 'He wandered down the stairs and into the basement', 'He wandered down the stairs and into the basement. The damp, musty smell of un-use hung in the air. A single, small window let in a glimmer of light, but this simply made the shadows in the basement deeper. He inhaled deeply and looked around at a mess that had been accumulating for over 25 years. He was positive that this was the place he wanted to live.', 'french,american'),
  (22, 'She has seen this scene before.', 'She has seen this scene before. It had come to her in dreams many times before. She had to pinch herself to make sure it wasn''t a dream again. As her fingers squeezed against her arm, she felt the pain. It was this pain that immediately woke her up.', 'classic,love,history'),
  (23, 'It''s an unfortunate reality that we don''t teach people how to make money', 'It''s an unfortunate reality that we don''t teach people how to make money (beyond getting a 9 to 5 job) as part of our education system. The truth is there are a lot of different, legitimate ways to make money. That doesn''t mean they are easy and that you won''t have to work hard to succeed, but it does mean that if you''re willing to open your mind a bit you don''t have to be stuck in an office from 9 to 5 for the next fifty years o your life.', 'crime,english'),
  (24, 'The robot clicked disapprovingly.', 'The robot clicked disapprovingly, gurgled briefly inside its cubical interior and extruded a pony glass of brownish liquid. "Sir, you will undoubtedly end up in a drunkard''s grave, dead of hepatic cirrhosis," it informed me virtuously as it returned my ID card. I glared as I pushed the glass across the table.', 'crime,american,love'),
  (25, 'It went through such rapid contortions', 'It went through such rapid contortions that the little bear was forced to change his hold on it so many times he became confused in the darkness, and could not, for the life of him, tell whether he held the sheep right side up, or upside down. But that point was decided for him a moment later by the animal itself, who, with a sudden twist, jabbed its horns so hard into his lowest ribs that he gave a grunt of anger and disgust.', 'fiction,history,french'),
  (26, 'She patiently waited for his number to be called.', 'She patiently waited for his number to be called. She had no desire to be there, but her mom had insisted that she go. She''s resisted at first, but over time she realized it was simply easier to appease her and go. Mom tended to be that way. She would keep insisting until you wore down and did what she wanted. So, here she sat, patiently waiting for her number to be called.', 'french,mystery,crime'),
  (27, 'Ten more steps.', 'If he could take ten more steps it would be over, but his legs wouldn''t move. He tried to will them to work, but they wouldn''t listen to his brain. Ten more steps and it would be over but it didn''t appear he would be able to do it.', 'mystery,classic,love'),
  (28, 'He had three simple rules by which he lived.', 'He had three simple rules by which he lived. The first was to never eat blue food. There was nothing in nature that was edible that was blue. People often asked about blueberries, but everyone knows those are actually purple. He understood it was one of the stranger rules to live by, but it had served him well thus far in the 50+ years of his life.', 'crime,love'),
  (29, 'The chair sat in the corner where it had been', 'The chair sat in the corner where it had been for over 25 years. The only difference was there was someone actually sitting in it. How long had it been since someone had done that? Ten years or more he imagined. Yet there was no denying the presence in the chair now.', 'mystery,american'),
  (30, 'Things aren''t going well at all', 'Things aren''t going well at all with mom today. She is just a limp noodle and wants to sleep all the time. I sure hope that things get better soon.', 'american,love,fiction');
