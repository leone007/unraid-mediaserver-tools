<?php
// The Language block of every settings page (Sysbox, Diskbox, Seedbox Tools, Streambox): the shared setting of
// lang.php. The page sets $mt_lang_post (this plugin's lang_save.php) and includes this file.
require_once __DIR__ . '/lang.php';
$mtl_c = mt_lang_cfg();
$mtl_u = mt_lang_code(mt_lang_unraid());
$mtl_var = @parse_ini_file('/var/local/emhttp/var.ini');
$mtl_tok = is_array($mtl_var) ? (string)($mtl_var['csrf_token'] ?? '') : '';
$mtl_ui_now = in_array($mtl_u, mt_lang_have('ui'), true) ? mt_lang_name($mtl_u) : mt_lang_name($mtl_u) . ', shown in English';
$mtl_dc_now = in_array($mtl_u, mt_lang_have('discord'), true) ? mt_lang_name($mtl_u) : mt_lang_name($mtl_u) . ', sent in English';
$mtl_h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
$mtl_sel = function ($a, $b) { return $a === $b ? ' selected' : ''; };
?>
<style>
#mtl{margin:28px 0 8px;max-width:1100px}
#mtl .mtl-band{display:flex;justify-content:space-between;align-items:center;gap:12px;background:rgba(0,0,0,.35);padding:10px 14px;text-transform:uppercase;letter-spacing:1.5px;font-size:12px}
#mtl .mtl-band b{font-weight:normal;text-transform:none;letter-spacing:0;opacity:.6}
#mtl .mtl-box{background:rgba(128,128,128,.08);border-radius:0 0 6px 6px}
#mtl .mtl-row{display:grid;grid-template-columns:220px minmax(0,1fr);gap:16px;padding:14px 16px;border-left:3px solid #8a8a8a;border-bottom:1px solid rgba(128,128,128,.15)}
#mtl .mtl-row label{padding-top:6px}
#mtl .mtl-row select{width:auto;min-width:260px;max-width:100%;margin:0}
#mtl .mtl-hint{display:block;opacity:.6;line-height:1.5;margin-top:6px;white-space:normal}
#mtl .mtl-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 16px}
#mtl .mtl-foot button{margin:0}
@media (max-width:700px){#mtl .mtl-row{grid-template-columns:1fr;gap:4px}}
</style>
<div id="mtl">
  <div class="mtl-band"><span>Language</span><b>shared by Sysbox, Diskbox, Seedbox and Streambox</b></div>
  <div class="mtl-box">
    <div class="mtl-row">
      <label for="mtl-ui">Pages and tiles:</label>
      <div>
        <select id="mtl-ui">
          <option value="auto"<?=$mtl_sel($mtl_c['ui'], 'auto')?>>Follow Unraid (now: <?=$mtl_h($mtl_ui_now)?>)</option>
          <option value="en"<?=$mtl_sel($mtl_c['ui'], 'en')?>>English</option>
          <option value="hu"<?=$mtl_sel($mtl_c['ui'], 'hu')?>>Magyar</option>
        </select>
        <span class="mtl-hint">Unraid's language is set under Settings, Display Settings. A language the plugins are not translated to yet shows in English. More languages come here as they are translated.</span>
      </div>
    </div>
    <div class="mtl-row">
      <label for="mtl-dc">Discord messages:</label>
      <div>
        <select id="mtl-dc">
          <option value="auto"<?=$mtl_sel($mtl_c['discord'], 'auto')?>>Follow Unraid (now: <?=$mtl_h($mtl_dc_now)?>)</option>
          <option value="en"<?=$mtl_sel($mtl_c['discord'], 'en')?>>English</option>
          <option value="hu"<?=$mtl_sel($mtl_c['discord'], 'hu')?>>Magyar</option>
        </select>
        <span class="mtl-hint">Every message of the four plugins: alerts, weekly summaries, new movies and series, the Discord bot. Movie and series titles stay as Jellyfin and Sonarr give them.</span>
      </div>
    </div>
    <div class="mtl-foot">
      <span id="mtl-msg" class="mtl-hint" style="margin:0">Saved for all plugins at once.</span>
      <button type="button" id="mtl-apply">Save language</button>
    </div>
  </div>
</div>
<script>
$(function () {
  // Unraid disables the Apply buttons of a page until its form changes: this block is outside every form, so it is a
  // button of its own name, enabled here (also after Unraid's own page scripts ran)
  var en = function () { $('#mtl-apply').prop('disabled', false).removeAttr('disabled'); };
  en(); setTimeout(en, 500); $('#mtl-ui, #mtl-dc').on('change', en);
  $('#mtl-apply').on('click', function () {
    var $b = $(this).prop('disabled', true), $m = $('#mtl-msg');
    $.post(<?=json_encode((string)$mt_lang_post)?>, { ui: $('#mtl-ui').val(), discord: $('#mtl-dc').val(), csrf_token: <?=json_encode($mtl_tok)?> }, null, 'json')
      .done(function (r) { $m.css({ color: r.ok ? '#3fa24a' : '#e5534b', opacity: 1 }).text(r.ok ? 'Saved for Sysbox, Diskbox, Seedbox and Streambox.' : (r.error || 'Not saved')); })
      .fail(function (x) { $m.css({ color: '#e5534b', opacity: 1 }).text((x.responseJSON && x.responseJSON.error) || 'Not saved'); })
      .always(function () { $b.prop('disabled', false); });
  });
});
</script>
