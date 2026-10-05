<div class="field">
    <label for="t-files">{{ __('Załączniki') }}</label>
    <input id="t-files" type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.log,.zip">
    <div class="hint">{{ __('Do :count plików, każdy do :size MB: obrazy, PDF, TXT, LOG, ZIP.', ['count' => \App\Domain\Tickets\TicketService::MAX_FILES, 'size' => \App\Domain\Tickets\TicketService::MAX_FILE_KB / 1024]) }}</div>
</div>
