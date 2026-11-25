<?php
/** Administer club news records and validate uploaded images. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/db.php';
require_admin();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST'); http_response_code(405); exit('Method not allowed.');
}


/** Never render or delete image paths outside our own upload directory. */

function newsRemoveImage(mixed $path): void {
    $path = sportsHubNewsUpload($path);
    if ($path === '') return;
    $disk = dirname(__DIR__) . $path;
    if (is_file($disk)) @unlink($disk);
}
/** Upload at most 2 MiB, verify MIME and image structure, choose own filename. */
function newsUploadImage(array $file): string {
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return '';
    if ($error !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
        throw new DomainException('Image upload failed. Select a JPEG, PNG or WebP file up to 2 MB.');
    }
    $size = $file['size'] ?? 0;
    if (!is_int($size) || $size < 1 || $size > 2 * 1024 * 1024) {
        throw new DomainException('News images must be smaller than 2 MB.');
    }
    $tmp = $file['tmp_name'];
    if (!is_uploaded_file($tmp)) throw new DomainException('Invalid image upload.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $formats = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!is_string($mime) || !isset($formats[$mime])) {
        throw new DomainException('Only JPEG, PNG and WebP images are supported.');
    }
    $image = @getimagesize($tmp);
    if ($image === false || $image[0] < 200 || $image[1] < 100 || $image[0] > 7000 || $image[1] > 7000) {
        throw new DomainException('Image dimensions must be between 200 × 100 and 7000 × 7000 pixels.');
    }
    $dir = dirname(__DIR__) . '/assets/uploads/club-news';
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new DomainException('News image storage is not writable. Check assets/uploads/club-news permissions.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $formats[$mime];
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        throw new DomainException('Could not store the uploaded image.');
    }
    @chmod($target, 0644);
    return '/assets/uploads/club-news/' . $filename;
}
if (empty($_SESSION['club_news_csrf']) || !is_string($_SESSION['club_news_csrf'])) {
    $_SESSION['club_news_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['club_news_csrf'];
$errors = [];
$loadError = false;
$imageSchemaReady = false;
$clubs = $stories = [];
$editing = null;
$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$editId = is_int($editId) ? $editId : 0;
$feedback = $_SESSION['club_news_feedback'] ?? null;
unset($_SESSION['club_news_feedback']);
$form = ['club_id'=>'', 'title'=>'', 'summary'=>'', 'status'=>'draft'];
$newsPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$newsPages = 1;
try {
    $pdo = db();
    $columns = $pdo->query("SHOW COLUMNS FROM club_news LIKE 'image_path'")->fetch(PDO::FETCH_ASSOC);
    $imageSchemaReady = is_array($columns);
    $imgSelect = $imageSchemaReady ? 'n.image_path' : 'NULL AS image_path';
    if ($method === 'POST') {
        if (!hash_equals($csrf, sportsHubInput('csrf_token'))) {
            http_response_code(403);
            $errors[] = 'Your session expired. Reload the page and retry.';
        } else {
            $action = sportsHubInput('action');
            $id = filter_var($_POST['news_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            $id = is_int($id) ? $id : 0;
            if ($action === 'save') {
                $form = [
                    'club_id'=>sportsHubInput('club_id'), 'title'=>sportsHubInput('title'),
                    'summary'=>sportsHubInput('summary'), 'status'=>sportsHubInput('status')
                ];
                $clubId = filter_var($form['club_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if (!is_int($clubId)) $errors[] = 'Choose an active sports club.';
                if (sportsHubLength($form['title']) < 3 || sportsHubLength($form['title']) > 160) $errors[] = 'Headline must be 3–160 characters.';
                if (sportsHubLength($form['summary']) < 10 || sportsHubLength($form['summary']) > 3000) $errors[] = 'Story must be 10–3000 characters.';
                if (!in_array($form['status'], ['draft','published'], true)) $errors[] = 'Choose a valid status.';
                $existing = null;
                if ($id) {
                    $get = $pdo->prepare('SELECT news_id, image_path FROM club_news WHERE news_id=? LIMIT 1');
                    if (!$imageSchemaReady) $get = $pdo->prepare('SELECT news_id, NULL AS image_path FROM club_news WHERE news_id=? LIMIT 1');
                    $get->execute([$id]); $existing = $get->fetch(PDO::FETCH_ASSOC);
                    if (!$existing) $errors[] = 'Story not found.';
                }
                if (!$errors) {
                    $check = $pdo->prepare("SELECT 1 FROM sports_clubs WHERE club_id=? AND status='active' LIMIT 1");
                    $check->execute([$clubId]);
                    if (!$check->fetchColumn()) $errors[] = 'Choose an active sports club.';
                }
                $upload = $_FILES['news_image'] ?? null;
                $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($hasUpload && !$imageSchemaReady) $errors[] = 'The connected database does not support news images. Contact an administrator to update its schema.';
                $remove = sportsHubInput('remove_image') === '1';
                $newImage = '';
                if (!$errors) {
                    try {
                        if ($hasUpload) $newImage = newsUploadImage($upload);
                        $pdo->beginTransaction();
                        if ($id) {
                            $field = $imageSchemaReady ? 'image_path' : 'NULL AS image_path';
                            $lock = $pdo->prepare("SELECT $field FROM club_news WHERE news_id=? FOR UPDATE");
                            $lock->execute([$id]);
                            $existing = $lock->fetch(PDO::FETCH_ASSOC);
                            if (!$existing) throw new DomainException('Story no longer exists.');
                        }
                        $image = $newImage !== '' ? $newImage : ($remove ? '' : ($existing['image_path'] ?? null));
                        if ($imageSchemaReady) {
                            if ($id) {
                                $stmt = $pdo->prepare("UPDATE club_news SET club_id=?,title=?,summary=?,status=?,image_path=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END WHERE news_id=?");
                                $stmt->execute([$clubId,$form['title'],$form['summary'],$form['status'],$image,$form['status'],$id]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO club_news (club_id,title,summary,status,image_path,published_at) VALUES (?,?,?,?,?,CASE WHEN ?='published' THEN NOW() ELSE NULL END)");
                                $stmt->execute([$clubId,$form['title'],$form['summary'],$form['status'],$image,$form['status']]);
                            }
                        } else {
                            if ($id) {
                                $stmt = $pdo->prepare("UPDATE club_news SET club_id=?,title=?,summary=?,status=?,published_at=CASE WHEN ?='published' THEN COALESCE(published_at,NOW()) ELSE NULL END WHERE news_id=?");
                                $stmt->execute([$clubId,$form['title'],$form['summary'],$form['status'],$form['status'],$id]);
                            } else {
                                $stmt = $pdo->prepare("INSERT INTO club_news (club_id,title,summary,status,published_at) VALUES (?,?,?,?,CASE WHEN ?='published' THEN NOW() ELSE NULL END)");
                                $stmt->execute([$clubId,$form['title'],$form['summary'],$form['status'],$form['status']]);
                            }
                        }
                        $pdo->commit();
                        if ($imageSchemaReady && ($newImage !== '' || $remove) && $existing) newsRemoveImage($existing['image_path']);
                        $_SESSION['club_news_feedback'] = $form['status'] === 'published'
                            ? 'Club news published.' : 'Club news draft saved.';
                        header('Location: /admin/news.php', true, 303); exit;
                    } catch (DomainException $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        if ($newImage !== '') newsRemoveImage($newImage);
                        $errors[] = $e->getMessage();
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        if ($newImage !== '') newsRemoveImage($newImage);
                        error_log('Saving club news: ' . $e->getMessage());
                        $errors[] = 'The story could not be saved. Check the server log.';
                    }
                }
                if ($id) $editing = ['news_id'=>$id, 'image_path'=>$existing['image_path'] ?? null];
            } elseif ($action === 'delete' && $id > 0) {
                $pdo->beginTransaction();
                $query = $imageSchemaReady ? 'SELECT image_path FROM club_news WHERE news_id=? FOR UPDATE' : 'SELECT NULL AS image_path FROM club_news WHERE news_id=? FOR UPDATE';
                $stmt = $pdo->prepare($query); $stmt->execute([$id]);
                $old = $stmt->fetch(PDO::FETCH_ASSOC);
                $del = $pdo->prepare('DELETE FROM club_news WHERE news_id=?'); $del->execute([$id]);
                $pdo->commit();
                if ($del->rowCount() > 0 && $old) newsRemoveImage($old['image_path']);
                $_SESSION['club_news_feedback'] = 'Story deleted.';
                header('Location: /admin/news.php', true, 303); exit;
            } else $errors[] = 'Invalid action.';
        }
    }
    $clubs = $pdo->query("SELECT club_id, club_name FROM sports_clubs WHERE status='active' ORDER BY club_name")->fetchAll(PDO::FETCH_ASSOC);
    $newsPages = max(1, (int) ceil((int) $pdo->query('SELECT COUNT(*) FROM club_news')->fetchColumn() / 24));
    $newsPage = min($newsPage, $newsPages);
    $offset = ($newsPage - 1) * 24;
    $sql = "SELECT n.news_id, n.club_id, n.title, n.summary, n.status, n.published_at,
                   n.created_at, {$imgSelect}, c.club_name
            FROM club_news n JOIN sports_clubs c ON c.club_id=n.club_id
            ORDER BY n.created_at DESC, n.news_id DESC LIMIT 24 OFFSET $offset";
    $stories = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if ($editId && $method !== 'POST') {
        foreach ($stories as $story) if ((int)$story['news_id'] === $editId) { $editing = $story; break; }
        if (!$editing) {
            $imageField = $imageSchemaReady ? 'image_path' : 'NULL AS image_path';
            $sql = "SELECT news_id,club_id,title,summary,status,{$imageField} FROM club_news WHERE news_id=?";
            $q = $pdo->prepare($sql); $q->execute([$editId]); $editing = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$editing) $errors[] = 'The requested story does not exist.';
        else $form = [
            'club_id'=>(string)$editing['club_id'],'title'=>(string)$editing['title'],
            'summary'=>(string)$editing['summary'],'status'=>(string)$editing['status']
        ];
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Club news administration: ' . $e->getMessage());
    $loadError = true;
    $errors[] = 'Unable to load club news. Check database permissions and logs.';
}
require __DIR__ . '/../includes/header.php';
?>
<main class="phase13-news-page shell phase14-news-page">
    <div class="phase13-news-heading">
        <div>
            <a class="phase13-back-link" href="/admin/manage.php">← Manage content</a>
            <h1>Club news.</h1>
            <p>Share sports club announcements, matchday stories and achievements with the SportsHub community.</p>
        </div>
    </div>
    <?php if (is_string($feedback) && $feedback !== ''): ?><p class="phase13-feedback" role="status"><?= sportsHubEscape($feedback) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="phase13-errors" role="alert"><?php foreach ($errors as $error): ?><p><?= sportsHubEscape($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if (!$imageSchemaReady && !$loadError): ?>
        <p class="phase14-migration-note" role="status">The connected database is missing <code>club_news.image_path</code>. Update the existing database schema before enabling image uploads. Do not import the full schema over existing records.</p>
    <?php endif; ?>
    <?php if (!$loadError): ?>
        <section class="phase13-news-form-section" id="news-editor" aria-labelledby="news-editor-title">
            <h2 id="news-editor-title"><?= $editing ? 'Edit news story' : 'Create a news story' ?></h2>
            <form method="post" action="/admin/news.php<?= $editing ? '?edit=' . (int)$editing['news_id'] : '' ?>"
                  class="phase13-news-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="news_id" value="<?= (int)($editing['news_id'] ?? 0) ?>">
                <div><label for="news-club">Sports club</label><select id="news-club" name="club_id" required><option value="">Choose a club</option><?php foreach ($clubs as $club): ?><option value="<?= (int)$club['club_id'] ?>" <?= (string)$club['club_id'] === $form['club_id'] ? 'selected' : '' ?>><?= sportsHubEscape($club['club_name']) ?></option><?php endforeach; ?></select></div>
                <div><label for="news-visibility">Publication status</label><select id="news-visibility" name="status"><option value="draft" <?= $form['status']==='draft' ? 'selected' : '' ?>>Draft — private</option><option value="published" <?= $form['status']==='published' ? 'selected' : '' ?>>Published — public</option></select></div>
                <div class="phase13-field-wide"><label for="news-title">Headline</label><input id="news-title" name="title" type="text" minlength="3" maxlength="160" required value="<?= sportsHubEscape($form['title']) ?>" placeholder="e.g. Cricket Club wins interfaculty championship"></div>
                <div class="phase13-field-wide"><label for="news-story">Story</label><textarea id="news-story" name="summary" minlength="10" maxlength="3000" rows="5" required placeholder="Share a club update, achievement, upcoming event or announcement."><?= sportsHubEscape($form['summary']) ?></textarea></div>
                <div class="phase13-field-wide phase14-image-uploader">
                    <label for="news-image">Cover image <span class="phase14-optional">(optional)</span></label>
                    <!-- The native file input covers the styled control to retain keyboard access. -->
                    <div class="news-upload-control">
                        <input id="news-image" type="file" name="news_image" accept="image/jpeg,image/png,image/webp" <?= !$imageSchemaReady ? 'disabled' : '' ?> aria-describedby="news-image-hint">
                        <span class="news-upload-button" aria-hidden="true">Choose image</span>
                        <span class="news-upload-filename" data-news-file-name aria-hidden="true">No image selected</span>
                    </div>
                    <p id="news-image-hint">Use a landscape JPEG, PNG or WebP, up to 2 MB. Recommended: 1200 × 675 pixels.</p>
                    <?php
                    $currentImage = sportsHubNewsUpload($editing['image_path'] ?? null);
                    if ($currentImage === '' && $editing && ($editing['image_path'] ?? null) === null) {
                        foreach (sportsHubOfficialNews() as $article) {
                            if ($form['title'] === $article['title'] || str_contains($form['summary'], $article['source'])) {
                                $currentImage = $article['image'];
                                break;
                            }
                        }
                    }
                    ?>
                    <?php if ($currentImage): ?>
                        <div class="phase14-existing-image"><img src="<?= sportsHubEscape($currentImage) ?>" alt="Current story cover image" loading="lazy"><label class="phase14-remove-image"><input type="checkbox" name="remove_image" value="1"> Remove existing image</label></div>
                    <?php endif; ?>
                    <img id="news-image-preview" class="phase14-upload-preview" alt="New cover image preview" hidden>
                </div>
                <div class="phase13-form-actions"><button class="button button-accent" type="submit"><?= $editing ? 'Save story' : 'Publish / save story' ?></button><?php if ($editing): ?><a class="button button-outline" href="/admin/news.php">Cancel</a><?php endif; ?></div>
            </form>
        </section>
        <section class="phase13-news-list" aria-labelledby="news-stories-heading">
            <h2 id="news-stories-heading">News library</h2>
            <?php if (!$stories): ?><p class="phase13-news-empty">No club news yet. Create a story using the editor above.</p><?php else: ?>
                <div class="phase13-news-grid">
                    <?php foreach ($stories as $story): ?>
                        <article class="phase13-news-card">
                            <?php $image = sportsHubNewsUpload($story['image_path'] ?? null); if ($image): ?><img class="phase14-news-cover" src="<?= sportsHubEscape($image) ?>" alt="" loading="lazy"><?php endif; ?>
                            <div class="phase13-news-meta"><span><?= sportsHubEscape($story['club_name']) ?></span><span><?= $story['status']==='published' ? 'Published' : 'Draft' ?></span></div>
                            <h3><?= sportsHubEscape($story['title']) ?></h3>
                            <p><?= sportsHubEscape($story['summary']) ?></p>
                            <div class="phase13-news-card-actions">
                                <a class="button button-outline" href="/admin/news.php?edit=<?= (int)$story['news_id'] ?>#news-editor">Edit</a>
                                <form method="post" action="/admin/news.php" onsubmit="return confirm('Delete this club news story permanently?')">
                                    <input type="hidden" name="csrf_token" value="<?= sportsHubEscape($csrf) ?>">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="news_id" value="<?= (int)$story['news_id'] ?>">
                                    <button type="submit" class="button button-outline phase13-delete">Delete</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($newsPages > 1): ?>
                <nav class="phase13-form-actions" aria-label="News library pagination">
                    <?php if ($newsPage > 1): ?><a class="button button-outline" href="?page=<?= $newsPage - 1 ?>#news-stories-heading">Previous</a><?php endif; ?>
                    <span>Page <?= $newsPage ?> of <?= $newsPages ?></span>
                    <?php if ($newsPage < $newsPages): ?><a class="button button-outline" href="?page=<?= $newsPage + 1 ?>#news-stories-heading">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
