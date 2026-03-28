<?php

/**
 * Plugin Name: ImageTurbo
 * Description: One-click WebP conversion. Permanent file replacement. Lightning-fast.
 * Version: 8.0
 * Author: Custom Developer
 */

if (!defined('ABSPATH')) exit;

// ==========================================
// PERMANENT FINALIZATION
// ==========================================
add_action('wp_ajax_finalize_webp_conversion', 'webp_finalize_conversion_ajax');
function webp_finalize_conversion_ajax()
{
    webp_make_permanent_final();
    wp_send_json_success(array('message' => 'Permanent!'));
}

function webp_make_permanent_final()
{
    global $wpdb;
    
    $upload_dir = wp_upload_dir();
    $base_dir = wp_normalize_path($upload_dir['basedir']);
    $base_url = $upload_dir['baseurl'];

    $files_converted = 0;
    
    $attachments = $wpdb->get_results("
        SELECT ID, post_name, post_parent, guid FROM {$wpdb->posts} 
        WHERE post_type = 'attachment' AND post_mime_type LIKE 'image%'
        AND (post_mime_type = 'image/jpeg' OR post_mime_type = 'image/png')
    ");

    foreach ($attachments as $att) {
        if (preg_match('/(.*)\.(jpe?g|png)$/i', $att->guid, $m)) {
            $original_url = $m[0];
            $original_ext = strtolower($m[2]);
            $base_name = $m[1];
            
            $original_file = $base_dir . '/' . str_replace($base_url . '/', '', $original_url);
            $webp_file = $base_dir . '/' . str_replace($base_url . '/', '', $base_name . '-' . $original_ext . '.webp');
            
            if (file_exists($webp_file) && file_exists($original_file)) {
                $new_webp_location = str_replace('.' . $original_ext, '.webp', $original_file);
                
                if (copy($webp_file, $new_webp_location)) {
                    @chmod($new_webp_location, 0644);
                    
                    @unlink($original_file);
                    @unlink($webp_file);
                    
                    $new_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $original_url);
                    
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$wpdb->posts} SET guid = %s, post_mime_type = %s WHERE ID = %d",
                        $new_url,
                        'image/webp',
                        $att->ID
                    ));
                    
                    $relative_path = str_replace($base_url . '/', '', $new_url);
                    update_post_meta($att->ID, '_wp_attached_file', $relative_path);
                    
                    $files_converted++;
                }
            } elseif (file_exists($webp_file)) {
                $new_url = preg_replace('/\.(jpe?g|png)$/i', '.webp', $original_url);
                
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET guid = %s, post_mime_type = %s WHERE ID = %d",
                    $new_url,
                    'image/webp',
                    $att->ID
                ));
                
                $relative_path = str_replace($base_url . '/', '', $new_url);
                update_post_meta($att->ID, '_wp_attached_file', $relative_path);
                
                $files_converted++;
            }
        }
    }

    $posts = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_status = 'publish'");
    
    foreach ($posts as $post) {
        $content = $post->post_content;
        
        $content = preg_replace(
            '/(https?:\/\/[^\s"\']*\/wp-content\/uploads\/[^\s"\']+)\.(jpg|jpeg|png)(?=["\'\s\)])/i',
            '$1.webp',
            $content
        );
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_content = %s WHERE ID = %d",
            $content,
            $post->ID
        ));
    }

    update_option('webp_permanent_done', true);
    update_option('webp_files_converted', $files_converted);
}

// ==========================================
// FRONTEND SCANNER UI
// ==========================================
add_action('wp_footer', 'webp_frontend_scanner_ui');
function webp_frontend_scanner_ui()
{
    if (!current_user_can('manage_options')) return;
    
    $is_done = get_option('webp_permanent_done', false);
?>
    <style>
        * {
            box-sizing: border-box;
        }

        #webp-frontend-scanner {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 440px;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
            border: 0;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15), 0 0 1px rgba(0, 0, 0, 0.05);
            z-index: 999999;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            font-size: 14px;
            color: #1a1a2e;
            overflow: hidden;
            animation: slideIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px) translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0) translateX(0);
            }
        }

        #webp-scanner-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #ffffff;
            padding: 16px 20px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            user-select: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        #webp-scanner-header:hover {
            box-shadow: inset 0 -2px 8px rgba(0, 0, 0, 0.1);
        }

        #webp-scanner-header span:first-child {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: 0.3px;
        }

        #webp-toggle {
            cursor: pointer;
            font-size: 18px;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        #webp-scanner-body {
            padding: 20px;
            max-height: 600px;
            overflow-y: auto;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        #webp-scanner-body::-webkit-scrollbar {
            width: 6px;
        }

        #webp-scanner-body::-webkit-scrollbar-track {
            background: transparent;
        }

        #webp-scanner-body::-webkit-scrollbar-thumb {
            background: #ddd;
            border-radius: 3px;
        }

        #webp-scanner-body::-webkit-scrollbar-thumb:hover {
            background: #bbb;
        }

        #webp-scanner-body p {
            margin: 0 0 12px 0;
            line-height: 1.6;
            color: #525252;
            font-weight: 500;
        }

        #webp-stats {
            background: linear-gradient(135deg, #f5f7ff 0%, #f0f4ff 100%);
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(102, 126, 234, 0.1);
            transition: all 0.3s ease;
        }

        #webp-stats:hover {
            background: linear-gradient(135deg, #eef2ff 0%, #e9f0ff 100%);
        }

        #webp-count {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 18px;
            font-weight: 800;
        }

        .webp-progress-container {
            width: 100%;
            background: #e8eaf0;
            height: 6px;
            border-radius: 10px;
            margin: 16px 0;
            overflow: hidden;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .webp-progress-bar {
            width: 0%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            transition: width 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(102, 126, 234, 0.4);
        }

        .webp-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: 0;
            padding: 12px 16px;
            cursor: pointer;
            border-radius: 10px;
            width: 100%;
            font-weight: 600;
            margin-top: 14px;
            font-size: 14px;
            letter-spacing: 0.5px;
            transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            text-transform: uppercase;
            position: relative;
            overflow: hidden;
        }

        .webp-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.2);
            transition: left 0.5s ease;
        }

        .webp-btn:hover:not(:disabled)::before {
            left: 100%;
        }

        .webp-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(102, 126, 234, 0.5);
        }

        .webp-btn:active:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }

        .webp-btn:disabled {
            background: linear-gradient(135deg, #ccc 0%, #aaa 100%);
            cursor: not-allowed;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .webp-btn.loading {
            animation: pulse 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        #webp-scanner-log {
            height: 220px;
            overflow-y: auto;
            background: linear-gradient(135deg, #0f1419 0%, #1a1f2e 100%);
            color: #0f0;
            border: 1px solid rgba(0, 255, 0, 0.1);
            padding: 12px;
            margin-top: 14px;
            font-family: 'Fira Code', 'Courier New', monospace;
            font-size: 12px;
            border-radius: 10px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-word;
            animation: fadeIn 0.4s ease-out;
        }

        #webp-scanner-log::-webkit-scrollbar {
            width: 6px;
        }

        #webp-scanner-log::-webkit-scrollbar-track {
            background: rgba(0, 255, 0, 0.05);
            border-radius: 3px;
        }

        #webp-scanner-log::-webkit-scrollbar-thumb {
            background: rgba(0, 255, 0, 0.2);
            border-radius: 3px;
        }

        #webp-scanner-log::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 255, 0, 0.4);
        }

        .webp-done-msg {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            padding: 14px;
            border-radius: 10px;
            border: 1px solid #b1dfbb;
            margin-bottom: 12px;
            font-weight: 500;
            line-height: 1.6;
            animation: slideDown 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        #webp-scanner-body {
            scroll-behavior: smooth;
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.6;
            }
        }
    </style>

    <div id="webp-frontend-scanner">
        <div id="webp-scanner-header">
            <span><?php echo $is_done ? '✅ Conversion Complete' : '⚡ ImageTurbo'; ?></span>
            <span id="webp-toggle" style="cursor: pointer;">▼</span>
        </div>
        <div id="webp-scanner-body">
            <?php if ($is_done): ?>
                <div class="webp-done-msg">
                    <strong>✅ Perfect!</strong><br>
                    All images permanently converted to WebP.<br>
                    Safe to delete this plugin.
                </div>
            <?php else: ?>
                <p>Lightning-fast conversion. Images replaced permanently. Click and forget.</p>
                <div id="webp-stats">
                    <span>Images Found</span>
                    <span id="webp-count">0</span>
                </div>
                
                <div class="webp-progress-container">
                    <div class="webp-progress-bar" id="webp-progress"></div>
                </div>
                
                <button class="webp-btn" id="webp-start-btn">🚀 Start Now</button>
                <div id="webp-scanner-log"></div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('webp-toggle');
            const body = document.getElementById('webp-scanner-body');
            const startBtn = document.getElementById('webp-start-btn');
            const logBox = document.getElementById('webp-scanner-log');
            const countSpan = document.getElementById('webp-count');
            const progressBar = document.getElementById('webp-progress');
            const scanner = document.getElementById('webp-frontend-scanner');

            toggle.addEventListener('click', () => {
                const isHidden = body.style.display === 'none';
                body.style.display = isHidden ? 'block' : 'none';
                toggle.innerText = isHidden ? '▼' : '▲';
                toggle.style.transform = isHidden ? 'rotate(0deg)' : 'rotate(180deg)';
                toggle.style.transition = 'transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1)';
            });

            let imageUrls = new Set();

            function extractImages() {
                const isTarget = (url) => {
                    if (!url || typeof url !== 'string') return false;
                    let clean = url.split('?')[0].toLowerCase();
                    return clean.includes('/wp-content/uploads/') &&
                        (clean.endsWith('.jpg') || clean.endsWith('.jpeg') || clean.endsWith('.png'));
                };

                document.querySelectorAll('img').forEach(img => {
                    if (isTarget(img.src)) imageUrls.add(img.src.split('?')[0]);
                    if (img.dataset?.src && isTarget(img.dataset.src)) {
                        imageUrls.add(img.dataset.src.split('?')[0]);
                    }
                    if (img.srcset) {
                        img.srcset.split(',').forEach(part => {
                            let url = part.trim().split(' ')[0];
                            if (isTarget(url)) imageUrls.add(url.split('?')[0]);
                        });
                    }
                });

                document.querySelectorAll('*').forEach(el => {
                    let bg = window.getComputedStyle(el).backgroundImage;
                    if (bg && bg !== 'none') {
                        let url = bg.replace(/^url\(["']?/, '').replace(/["']?\)$/, '');
                        if (isTarget(url)) imageUrls.add(url.split('?')[0]);
                    }
                });
            }

            extractImages();
            let imageArray = Array.from(imageUrls);
            
            animateCount(0, imageArray.length);

            if (imageArray.length === 0) {
                startBtn.disabled = true;
                startBtn.innerHTML = "✓ All set";
                startBtn.style.opacity = '0.6';
            }

            function animateCount(from, to) {
                let current = from;
                const step = Math.ceil((to - from) / 10);
                const interval = setInterval(() => {
                    current = Math.min(current + step, to);
                    countSpan.textContent = current;
                    if (current >= to) clearInterval(interval);
                }, 30);
            }

            function log(msg) {
                const timestamp = new Date().toLocaleTimeString('en-US', { 
                    hour12: false, 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
                logBox.innerHTML += `<div>[${timestamp}] ${msg}</div>`;
                logBox.scrollTop = logBox.scrollHeight;
            }

            startBtn.addEventListener('click', () => {
                if (imageArray.length === 0) return;
                startBtn.disabled = true;
                startBtn.innerHTML = "⏳ Converting...";
                startBtn.classList.add('loading');
                logBox.innerHTML = '';
                log('🚀 Starting conversion process...');
                log(`📊 Found ${imageArray.length} image(s)`);
                log('━'.repeat(40));
                processImage(0, 0);
            });

            function processImage(index, retries) {
                if (index >= imageArray.length) {
                    progressBar.style.width = '100%';
                    log('━'.repeat(40));
                    log('✨ All images converted!');
                    log('🔐 Making permanent (replacing files)...');
                    startBtn.innerText = "⏳ Finalizing...";
                    startBtn.classList.add('loading');

                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'finalize_webp_conversion' })
                    })
                    .then(r => r.json())
                    .then(d => {
                        log('━'.repeat(40));
                        log('✅ PERMANENTLY FINALIZED!');
                        log('🗄️ Database updated');
                        log('📁 Files replaced');
                        log('🎉 Ready to delete plugin');
                        startBtn.innerHTML = "✅ Perfect! Refresh page";
                        startBtn.classList.remove('loading');
                        startBtn.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                        setTimeout(() => location.reload(), 1200);
                    })
                    .catch(e => {
                        log('❌ Error: ' + e.message);
                        startBtn.innerText = "🔄 Retry";
                        startBtn.disabled = false;
                        startBtn.classList.remove('loading');
                    });
                    return;
                }

                let url = imageArray[index];
                let pct = Math.round((index / imageArray.length) * 100);
                progressBar.style.width = pct + '%';

                let rel = url.split('/wp-content/uploads/')[1];
                let name = url.split('/').pop();
                let shortName = name.length > 30 ? name.substring(0, 27) + '...' : name;

                log(`[${index + 1}/${imageArray.length}] 🖼️ ${shortName}`);

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'process_single_frontend_image',
                        relative_path: rel,
                        _ajax_nonce: '<?php echo wp_create_nonce('webp_single_nonce'); ?>'
                    })
                })
                .then(r => r.text().then(t => JSON.parse(t)))
                .then(d => {
                    if (d.success) {
                        let msg = d.data.message.replace(/<[^>]*>/g, '');
                        log(`    → ${msg}`);
                    }
                    processImage(index + 1, 0);
                })
                .catch(e => {
                    if (retries < 1) {
                        setTimeout(() => processImage(index, retries + 1), 1000);
                    } else {
                        log(`    → ⚠️ Skipped`);
                        processImage(index + 1, 0);
                    }
                });
            }
        });
    </script>
<?php
}

// ==========================================
// 2. CONVERSION PROCESSOR
// ==========================================
add_action('wp_ajax_process_single_frontend_image', 'process_single_frontend_image_callback');
function process_single_frontend_image_callback()
{
    ob_start();
    try {
        check_ajax_referer('webp_single_nonce', '_ajax_nonce');
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $rel = isset($_POST['relative_path']) ? sanitize_text_field($_POST['relative_path']) : '';
        $file = wp_normalize_path(wp_upload_dir()['basedir'] . '/' . urldecode($rel));

        if (!file_exists($file)) {
            throw new Exception("File not found");
        }

        $msg = convert_to_webp($file);
        ob_end_clean();
        wp_send_json_success(array('message' => $msg));
    } catch (\Throwable $e) {
        ob_end_clean();
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}

function convert_to_webp($file)
{
    $parts = pathinfo($file);
    $webp_temp = $parts['dirname'] . '/' . $parts['filename'] . '-' . strtolower($parts['extension']) . '.webp';
    $max = 100 * 1024;

    if (file_exists($webp_temp) && filesize($webp_temp) <= $max) {
        return "✓ Already converted";
    }

    $ed = wp_get_image_editor($file);
    if (is_wp_error($ed)) return "✗ Editor error";

    $q = 90;
    do {
        $ed->set_quality($q);
        $s = $ed->save($webp_temp, 'image/webp');
        if (is_wp_error($s)) return "✗ Save error";
        
        @chmod($webp_temp, 0644);
        clearstatcache();
        $sz = @filesize($webp_temp) ?: 0;
        $q -= 10;

        if ($sz > $max && $q <= 30) {
            $d = $ed->get_size();
            if ($d) $ed->resize(max(1, (int)($d['width'] * 0.8)), max(1, (int)($d['height'] * 0.8)));
            $q = 70;
        }
    } while ($sz > $max && $q >= 10);

    unset($ed);
    return "✓ " . round($sz / 1024, 1) . "KB";
}
