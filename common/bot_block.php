<?php
// The Discord bot block (Seedbox Tools settings, Sysbox Claude Guard; item 95): the shared setting of bot.php. The page
// sets $mt_bot_post (this plugin's bot_save.php) and includes this file. The token never goes into the page.
require_once __DIR__ . '/bot.php';
$mtb_c = mt_bot_cfg();
$mtb_var = @parse_ini_file('/var/local/emhttp/var.ini');
$mtb_tok = is_array($mtb_var) ? (string)($mtb_var['csrf_token'] ?? '') : '';
$mtb_h = function ($k, $v = []) { return htmlspecialchars(mt_bot_t($k, $v), ENT_QUOTES); };
$mtb_state = $mtb_c['token'] === '' ? mt_bot_t('bot.st.off') : ($mtb_c['user'] === '' ? mt_bot_t('bot.st.no_user') : mt_bot_t('bot.st.on'));
?>
<style>
#mtb{margin:28px 0 8px;max-width:1100px}
#mtb .mtb-band{display:flex;justify-content:space-between;align-items:center;gap:12px;background:rgba(0,0,0,.35);padding:10px 14px;text-transform:uppercase;letter-spacing:1.5px;font-size:12px}
#mtb .mtb-band b{font-weight:normal;text-transform:none;letter-spacing:0;opacity:.6}
#mtb .mtb-box{background:rgba(128,128,128,.08);border-radius:0 0 6px 6px}
#mtb .mtb-row{display:grid;grid-template-columns:220px minmax(0,1fr);gap:16px;padding:14px 16px;border-left:3px solid #8a8a8a;border-bottom:1px solid rgba(128,128,128,.15)}
#mtb .mtb-row.on{border-left-color:#3fa24a}
#mtb .mtb-row label{padding-top:6px}
#mtb .mtb-row input[type=password],#mtb .mtb-row input[type=text]{width:320px;max-width:100%;margin:0}
#mtb .mtb-hint{display:block;opacity:.6;line-height:1.5;margin-top:6px;white-space:normal}
#mtb .mtb-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 16px;flex-wrap:wrap}
#mtb .mtb-foot button{margin:0 0 0 8px}
#mtb .mtb-foot .mtb-ghost{background:transparent;border:1px solid rgba(128,128,128,.5)}
@media (max-width:700px){#mtb .mtb-row{grid-template-columns:1fr;gap:4px}}
</style>
<div id="mtb">
  <div class="mtb-band"><span><?=$mtb_h('bot.h.band')?></span><b id="mtb-state"><?=htmlspecialchars($mtb_state, ENT_QUOTES)?></b></div>
  <div class="mtb-box">
    <div class="mtb-row<?=$mtb_c['token'] !== '' ? ' on' : ''?>">
      <label for="mtb-token"><?=$mtb_h('bot.l.token')?></label>
      <div>
        <input type="password" id="mtb-token" autocomplete="new-password" placeholder="<?=$mtb_h($mtb_c['token'] !== '' ? 'bot.ph.saved' : 'bot.ph.token')?>">
        <span class="mtb-hint"><?=$mtb_h('bot.hint.token')?></span>
      </div>
    </div>
    <div class="mtb-row<?=$mtb_c['user'] !== '' ? ' on' : ''?>">
      <label for="mtb-user"><?=$mtb_h('bot.l.user')?></label>
      <div>
        <input type="text" id="mtb-user" inputmode="numeric" value="<?=htmlspecialchars($mtb_c['user'], ENT_QUOTES)?>" placeholder="<?=$mtb_h('bot.ph.user')?>">
        <span class="mtb-hint"><?=$mtb_h('bot.hint.user')?></span>
      </div>
    </div>
    <div class="mtb-foot">
      <span id="mtb-msg" class="mtb-hint" style="margin:0"><?=$mtb_h('bot.hint.foot')?></span>
      <span><button type="button" id="mtb-test" class="mtb-ghost"><?=$mtb_h('bot.btn.test')?></button><button type="button" id="mtb-apply"><?=$mtb_h('bot.btn.save')?></button></span>
    </div>
  </div>
</div>
<script>
$(function () {
  // outside every form: Unraid's Apply-disabling does not apply, enable the buttons after Unraid's scripts ran (like the Language block)
  var en = function () { $('#mtb-apply, #mtb-test').prop('disabled', false).removeAttr('disabled'); };
  en(); setTimeout(en, 500);
  var URL = <?=json_encode((string)$mt_bot_post)?>, TOK = <?=json_encode($mtb_tok)?>;
  var TX = <?=json_encode(['wait' => mt_bot_t('bot.wait'), 'fail' => mt_bot_t('bot.fail'), 'saved_ph' => mt_bot_t('bot.ph.saved'), 'ph' => mt_bot_t('bot.ph.token'), 'on' => mt_bot_t('bot.st.on'), 'off' => mt_bot_t('bot.st.off'), 'no_user' => mt_bot_t('bot.st.no_user')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)?>;
  var show = function (ok, t) { $('#mtb-msg').css({ color: ok ? '#3fa24a' : '#e5534b', opacity: 1 }).text(t); };
  var call = function ($b, data, done) {
    $b.prop('disabled', true); $('#mtb-msg').css({ color: '', opacity: .6 }).text(TX.wait);
    $.post(URL, $.extend({ csrf_token: TOK }, data), null, 'json')
      .done(function (r) { show(r.ok, r.ok ? r.msg : (r.error || TX.fail)); if (r.ok && done) { done(r); } })
      .fail(function (x) { show(false, (x.responseJSON && x.responseJSON.error) || TX.fail); })
      .always(function () { $b.prop('disabled', false); });
  };
  $('#mtb-apply').on('click', function () {
    var u = $.trim($('#mtb-user').val());
    call($(this), { op: 'save', token: $.trim($('#mtb-token').val()), user: u }, function (r) {
      $('#mtb-token').val('').attr('placeholder', r.token_set ? TX.saved_ph : TX.ph);
      $('#mtb-token').closest('.mtb-row').toggleClass('on', r.token_set); $('#mtb-user').closest('.mtb-row').toggleClass('on', u !== '');
      $('#mtb-state').text(!r.token_set ? TX.off : (u === '' ? TX.no_user : TX.on));
    });
  });
  $('#mtb-test').on('click', function () { call($(this), { op: 'test' }); });
});
</script>
