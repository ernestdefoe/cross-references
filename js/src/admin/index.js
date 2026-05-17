import app from 'flarum/admin/app';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe-cross-references', () => {
  // No additional admin-page side-effects beyond the settings + permissions
  // registered via the Admin extender in ./extend.
});
