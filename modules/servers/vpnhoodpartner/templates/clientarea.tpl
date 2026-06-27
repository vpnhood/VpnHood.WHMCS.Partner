<p>Your VPN access code is ready. Use it to activate VpnHood.</p>

{if $accessCode}
    <div class="alert alert-success" style="font-size:1.1em;">
        <strong>Access Code:</strong>
        <span id="vhAccessCode">{$accessCode|escape}</span>
    </div>
    <button id="vhCopyCode" class="btn btn-default btn-sm" type="button">Copy code</button>
{else}
    <div class="alert alert-warning">
        Your access code is not available yet. Please check back shortly or contact support.
    </div>
{/if}

{literal}
<script>
document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('vhCopyCode');
    var code = document.getElementById('vhAccessCode');
    if (btn && code && navigator.clipboard) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(code.textContent.trim()).then(function () {
                btn.textContent = 'Copied!';
                setTimeout(function () { btn.textContent = 'Copy code'; }, 1500);
            });
        });
    }
});
</script>
{/literal}
