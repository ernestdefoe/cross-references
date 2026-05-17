import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';

export default [
  new Extend.Admin()
    .setting(() => ({
      setting: 'ernestdefoe-cross-references.showInline',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-cross-references.admin.settings.show_inline_label'),
      help: app.translator.trans('ernestdefoe-cross-references.admin.settings.show_inline_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-cross-references.createBacklinks',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-cross-references.admin.settings.create_backlinks_label'),
      help: app.translator.trans('ernestdefoe-cross-references.admin.settings.create_backlinks_help'),
    }))
    .setting(() => ({
      setting: 'ernestdefoe-cross-references.notifyAuthor',
      type: 'boolean',
      label: app.translator.trans('ernestdefoe-cross-references.admin.settings.notify_author_label'),
      help: app.translator.trans('ernestdefoe-cross-references.admin.settings.notify_author_help'),
    })),
];
