(() => {
  if (!window.Quill) return;

  document.querySelectorAll('textarea[data-blog-editor]').forEach((textarea) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'blog-editor';
    const toolbar = document.createElement('div');
    toolbar.innerHTML = '<span class="ql-formats"><select class="ql-header" aria-label="Text style"><option selected>Paragraph</option><option value="2">Heading 2</option><option value="3">Heading 3</option></select></span>' +
      '<span class="ql-formats"><button type="button" class="ql-bold" title="Bold"></button><button type="button" class="ql-italic" title="Italic"></button><button type="button" class="ql-underline" title="Underline"></button><button type="button" class="ql-strike" title="Strikethrough"></button></span>' +
      '<span class="ql-formats"><button type="button" class="ql-list" value="bullet" title="Bulleted list"></button><button type="button" class="ql-list" value="ordered" title="Numbered list"></button><button type="button" class="ql-blockquote" title="Quote"></button><button type="button" class="ql-link" title="Link"></button><button type="button" class="ql-clean" title="Clear formatting"></button></span>' +
      '<span class="ql-formats"><button type="button" class="editor-undo" title="Undo" aria-label="Undo">&#8630;</button><button type="button" class="editor-redo" title="Redo" aria-label="Redo">&#8631;</button></span>';
    const surface = document.createElement('div');
    const footer = document.createElement('div');
    footer.className = 'blog-editor-footer';
    const count = document.createElement('span');
    const source = document.createElement('button');
    source.type = 'button';
    source.textContent = 'HTML';
    source.setAttribute('aria-pressed', 'false');
    footer.append(count, source);
    textarea.before(wrapper);
    wrapper.append(toolbar, surface, textarea, footer);
    const editor = new Quill(surface, {
      theme: 'snow',
      placeholder: 'Write your post...',
      modules: { toolbar, history: { userOnly: true } },
      formats: ['header', 'bold', 'italic', 'underline', 'strike', 'list', 'blockquote', 'link', 'indent', 'align', 'image', 'code-block', 'script']
    });
    editor.root.id = textarea.id + '-visual';
    editor.root.setAttribute('role', 'textbox');
    editor.root.setAttribute('aria-multiline', 'true');
    editor.root.setAttribute('aria-label', 'Post content');
    const label = document.querySelector('label[for="' + textarea.id + '"]');
    if (label) label.htmlFor = editor.root.id;
    const required = textarea.required;
    textarea.required = false;
    textarea.hidden = true;
    let sourceMode = false;
    const load = () => {
      if (/<\/?[a-z][^>]*>/i.test(textarea.value)) {
        editor.setContents(editor.clipboard.convert({ html: textarea.value }), 'silent');
      } else {
        editor.setText(textarea.value, 'silent');
      }
      editor.history.clear();
    };
    const updateCount = () => {
      const text = editor.getText().trim();
      const words = text ? text.split(/\s+/).length : 0;
      count.textContent = words + (words === 1 ? ' word' : ' words');
    };
    load();
    updateCount();
    editor.on('text-change', () => {
      textarea.value = editor.getSemanticHTML();
      updateCount();
    });
    toolbar.querySelector('.editor-undo').onclick = () => editor.history.undo();
    toolbar.querySelector('.editor-redo').onclick = () => editor.history.redo();
    source.onclick = () => {
      sourceMode = !sourceMode;
      if (!sourceMode) { load(); updateCount(); }
      textarea.hidden = !sourceMode;
      surface.hidden = sourceMode;
      toolbar.hidden = sourceMode;
      source.textContent = sourceMode ? 'Visual' : 'HTML';
      source.setAttribute('aria-pressed', String(sourceMode));
      if (label) label.htmlFor = sourceMode ? textarea.id : editor.root.id;
      if (sourceMode) textarea.focus(); else editor.focus();
    };
    textarea.form.addEventListener('submit', (event) => {
      const probe = document.createElement('div');
      probe.innerHTML = textarea.value;
      if (required && !probe.textContent.replace(/\u00a0/g, ' ').trim() && !probe.querySelector('img[src]')) {
        event.preventDefault();
        if (sourceMode) textarea.focus(); else editor.focus();
        editor.root.setAttribute('aria-invalid', 'true');
        count.textContent = 'Content is required.';
      } else {
        editor.root.removeAttribute('aria-invalid');
      }
    });
  });
})();
