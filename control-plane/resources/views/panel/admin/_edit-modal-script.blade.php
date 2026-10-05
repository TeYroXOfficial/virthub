{{-- Okno edycji, które wróciło z błędami walidacji, otwiera się samo. --}}
<script>
    document.querySelectorAll('dialog.edit-modal[data-open]').forEach(function (d) { if (d.showModal) d.showModal(); });
</script>
