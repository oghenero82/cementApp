export class RouteLinkBuilder {
  static collection(type) {
    return '#records/' + encodeURIComponent(type);
  }
  static record(type, id) {
    return '#records/' + encodeURIComponent(type) + '/' + encodeURIComponent(id);
  }
  static workspace(key) {
    return '#workspace/' + encodeURIComponent(key);
  }
}
