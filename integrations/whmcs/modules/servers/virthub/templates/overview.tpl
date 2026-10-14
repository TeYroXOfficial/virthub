{* VirtHub — stan serwera i sterowanie w panelu klienta WHMCS *}
{assign var=s value=$vh.service}
{assign var=base value="clientarea.php?action=productdetails&id=`$serviceid`&modop=custom&a="}
<div class="virthub card mb-4" style="margin-bottom:20px">
    <div class="card-body panel-body">
        <h3 class="card-title" style="margin-top:0">
            {$s.hostname|escape}
            <span class="label badge {if $s.state eq 'running'}label-success bg-success{elseif $s.state eq 'error'}label-danger bg-danger{else}label-default bg-secondary{/if}">{$vh.state_label|escape}</span>
        </h3>
        {if $s.state eq 'building'}
            <div class="progress" style="margin:10px 0"><div class="progress-bar" role="progressbar" style="width: {$s.build_progress}%">{$vh.lang.build_progress|replace:':percent':$s.build_progress|escape}</div></div>
        {/if}
        {if $s.busy}<div class="alert alert-info">{$vh.lang.busy|escape}</div>{/if}

        <div class="row">
            <div class="col-sm-6">
                <table class="table table-condensed table-sm">
                    <tr><th>{$vh.lang.ip_addresses|escape}</th><td>{foreach $vh.ips as $ip}<code>{$ip|escape}</code><br>{/foreach}</td></tr>
                    <tr><th>{$vh.lang.os|escape}</th><td>{if $s.guest_os}{$s.guest_os.name|escape}{elseif $s.os}{$s.os.name|escape}{/if}</td></tr>
                    <tr><th>{$vh.lang.resources|escape}</th><td>{$s.resources.vcpu} {$vh.lang.vcpu|escape} · {$s.resources.ram_mb} MB {$vh.lang.ram|escape} · {$s.resources.disk_gb} GB</td></tr>
                    <tr><th>{$vh.lang.traffic|escape}</th><td>{$vh.traffic_text|escape}
                        {if $vh.traffic_percent !== null}<div class="progress" style="height:6px; margin:4px 0 0"><div class="progress-bar" style="width: {$vh.traffic_percent}%"></div></div>{/if}</td></tr>
                </table>
            </div>
            <div class="col-sm-6">
                <h4>{$vh.lang.actions|escape}</h4>
                <div class="btn-group-wrap" style="display:flex; flex-wrap:wrap; gap:6px">
                    {if !$vh.running}
                        <form method="post" action="{$base}start"><input type="hidden" name="token" value="{$token}"><button class="btn btn-success btn-sm" type="submit">{$vh.lang.start|escape}</button></form>
                    {else}
                        <form method="post" action="{$base}reboot"><input type="hidden" name="token" value="{$token}"><button class="btn btn-default btn-secondary btn-sm" type="submit">{$vh.lang.reboot|escape}</button></form>
                        <form method="post" action="{$base}stop"><input type="hidden" name="token" value="{$token}"><button class="btn btn-warning btn-sm" type="submit">{$vh.lang.stop|escape}</button></form>
                        <form method="post" action="{$base}forceoff" onsubmit="return confirm('{$vh.lang.confirm_force_off|escape:'javascript'}')"><input type="hidden" name="token" value="{$token}"><button class="btn btn-danger btn-sm" type="submit">{$vh.lang.force_off|escape}</button></form>
                        <a class="btn btn-default btn-secondary btn-sm" href="{$base}console" target="_blank" rel="noopener">{$vh.lang.console|escape}</a>
                    {/if}
                    <form method="post" action="{$base}resetpassword" onsubmit="return confirm('{$vh.lang.confirm_reset_password|escape:'javascript'}')"><input type="hidden" name="token" value="{$token}"><button class="btn btn-default btn-secondary btn-sm" type="submit">{$vh.lang.reset_password|escape}</button></form>
                    <a class="btn btn-primary btn-sm" href="clientarea.php?action=productdetails&amp;id={$serviceid}&amp;dosinglesignon=1" target="_blank" rel="noopener">{$vh.lang.open_panel|escape}</a>
                </div>

                {if $vh.templates}
                    <h4 style="margin-top:18px">{$vh.lang.reinstall|escape}</h4>
                    <form method="post" action="{$base}reinstall">
                        <input type="hidden" name="token" value="{$token}">
                        <p class="text-muted small">{$vh.lang.reinstall_warning|escape}</p>
                        <select name="template" class="form-control" required aria-label="{$vh.lang.reinstall_select|escape}">
                            {foreach $vh.templates as $t}<option value="{$t.id}" {if $s.os && $s.os.id eq $t.id}selected{/if}>{$t.name|escape}</option>{/foreach}
                        </select>
                        <div class="checkbox form-check" style="margin:8px 0"><label><input type="checkbox" name="confirm" value="1" required> {$vh.lang.reinstall_confirm|escape}</label></div>
                        <button class="btn btn-danger btn-sm" type="submit">{$vh.lang.reinstall|escape}</button>
                    </form>
                {/if}
            </div>
        </div>
    </div>
</div>
