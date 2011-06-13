/**
 * @provides differential-inline-comment-editor
 * @requires javelin-dom
 *           javelin-workflow
 *           javelin-util
 *           javelin-stratcom
 *           javelin-install
 */

JX.install('DifferentialInlineCommentEditor', {

  construct : function(uri) {
    this._uri = uri;
  },

  events : ['done'],

  members : {
    _uri : null,
    _undoText : null,
    _skipOverInlineCommentRows : function(node) {
      // TODO: Move this semantic information out of class names.
      while (node && node.className.indexOf('inline') !== -1) {
        node = node.nextSibling;
      }
      return node;
    },
    _buildRequestData : function() {
      return {
        op : this.getOperation(),
        on_right : this.getOnRight(),
        id : this.getID(),
        number : this.getLineNumber(),
        is_new : this.getIsNew(),
        length : this.getLength(),
        changeset : this.getChangeset(),
        text : this.getText() || ''
      };
    },
    _draw : function(content, exact_row) {
      var row = this.getRow();
      var table = this.getTable();
      var target = exact_row ? row : this._skipOverInlineCommentRows(row);

      return copyRows(table, content, target);
    },
    _removeUndoLink : function() {
      var rows = JX.DifferentialInlineCommentEditor._undoRows;
      if (rows) {
        for (var ii = 0; ii < rows.length; ii++) {
          JX.DOM.remove(rows[ii]);
        }
      }
    },
    _undo : function() {
      this._removeUndoLink();

      this.setText(this._undoText);
      this.start();
    },
    _registerUndoListener : function() {
      if (!JX.DifferentialInlineCommentEditor._activeEditor) {
        JX.Stratcom.listen(
          'click',
          'differential-inline-comment-undo',
          function(e) {
            JX.DifferentialInlineCommentEditor._activeEditor._undo();
            e.kill();
          });
      }
      JX.DifferentialInlineCommentEditor._activeEditor = this;
    },
    _didCompleteWorkflow : function(response) {
      var op = this.getOperation();

      // We don't get any markup back if the user deletes a comment, or saves
      // an empty comment (which effects a delete).
      if (response.markup) {
        this._draw(JX.$N('div', JX.$H(response.markup)));
      }

      // These operations remove the old row (edit adds a new row first).
      var remove_old = (op == 'edit' || op == 'delete');
      if (remove_old) {
        JX.DOM.remove(this.getRow());
      }

      // Once the user saves something, get rid of the 'undo' option. A
      // particular case where we need this is saving a delete, when we might
      // otherwise leave around an 'undo' for an earlier edit to the same
      // comment.
      this._removeUndoLink();

      JX.Stratcom.invoke('differential-inline-comment-update');
      this.invoke('done');
    },
    _didCancelWorkflow : function() {
      this.invoke('done');

      var op = this.getOperation();
      if (op == 'delete') {
        // No undo for delete, we prompt the user explicitly.
        return;
      }

      try {
        var textarea = JX.DOM.find(
          document.body, // TODO: use getDialogRootNode() when available
          'textarea',
          'differential-inline-comment-edit-textarea');
      } catch (ex) {
        if (ex !== JX.$.NotFound) {
          throw ex;
        }
        // The close handler is called whenever the dialog closes, even if the
        // user closed it by completing the workflow with "Save". The
        // JX.Workflow API should probably be refined to allow programmatic
        // distinction of close caused by 'cancel' vs 'submit'. Testing for
        // presence of the textarea serves as a proxy for detecting a 'cancel'.
        return;
      }

      var text = textarea.value;

      // If the user hasn't edited the text (i.e., no change from original for
      // 'edit' or no text for 'new' or 'reply'), don't offer them an undo.
      if (text == (this.getOriginalText() || '')) {
        return;
      }

      // Save the text so we can 'undo' back to it.
      this._undoText = text;

      var template = this.getOnRight()
        ? this.getTemplates().r
        : this.getTemplates().l;
      template = JX.$N('div', JX.$H(template));

      // NOTE: Operation order matters here; we can't remove anything until
      // after we draw the new rows because _draw uses the old rows to figure
      // out where to place the comment.

      // We use 'exact_row' to put the "undo" text directly above the affected
      // comment.
      var exact_row = true;
      var rows = this._draw(template, exact_row);

      this._removeUndoLink();

      JX.DifferentialInlineCommentEditor._undoRows = rows;
    },

    start : function() {
      this._registerUndoListener();

      var data = this._buildRequestData();
      var handler = JX.bind(this, this._didCompleteWorkflow);
      var close_handler = JX.bind(this, this._didCancelWorkflow);

       new JX.Workflow(this._uri, data)
        .setHandler(handler)
        .setCloseHandler(close_handler)
        .start();

      return this;
    }
  },

  statics : {
    /**
     * Global refernece to the 'undo' rows currently rendered in the document.
     */
    _undoRows : null,

    /**
     * Global listener for the 'undo' click associated with the currently
     * displayed 'undo' link. When an editor is start()ed, it becomes the active
     * editor.
     */
    _activeEditor : null
  },

  properties : {
    operation : null,
    row : null,
    table : null,
    onRight : null,
    ID : null,
    lineNumber : null,
    changeset : null,
    length : null,
    isNew : null,
    text : null,
    templates : null,
    originalText : null
  }

});