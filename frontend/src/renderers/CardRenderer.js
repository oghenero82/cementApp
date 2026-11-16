import { el, icon } from '../core/dom.js';
export class CardRenderer {
  static metric(title, value, caption = '', kind = 'grid') {
    return el(
      'article',
      { class: 'card stat-card' },
      el(
        'div',
        { class: 'stat-top' },
        el('span', { class: 'stat-icon' }, icon(kind)),
        el('span', { class: 'metric-caption' }, caption),
      ),
      el('p', { class: 'stat-title' }, title),
      el('strong', { class: 'stat-value' }, value),
    );
  }
  static empty(title, message, action) {
    return el(
      'div',
      { class: 'empty' },
      icon('box'),
      el('h3', {}, title),
      el('p', {}, message),
      action,
    );
  }
  static panel(title, body, action) {
    return el(
      'section',
      { class: 'card card-pad' },
      el('div', { class: 'card-head' }, el('h3', {}, title), action),
      body,
    );
  }
}
