const menuItems = [
  {
    name: 'Dashboard',
    selector: 'navigation-dashboard',
    pageSelector: 'dashboard-page',
    caption: 'Dashboard — общее состояние каталога и продуктов',
  },
  {
    name: 'Company settings',
    selector: 'navigation-company-settings',
    pageSelector: null,
    caption: 'Company — данные и настройки организации',
  },
  {
    name: 'Products',
    selector: 'navigation-products',
    pageSelector: 'products-page',
    caption: 'Products — управление товарами компании',
  },
  {
    name: 'Categories',
    selector: 'navigation-categories',
    pageSelector: 'categories-page',
    caption: 'Categories — структура каталога',
  },
  {
    name: 'Attributes',
    selector: 'navigation-attributes',
    pageSelector: null,
    caption: 'Attributes — характеристики и свойства товаров',
  },
  {
    name: 'Members',
    selector: 'navigation-members',
    pageSelector: null,
    caption: 'Team — пользователи, роли и доступы',
  },
  {
    name: 'Audit',
    selector: 'navigation-audit',
    pageSelector: null,
    caption: 'Activity log — история действий в компании',
  },
  {
    name: 'API tokens',
    selector: 'navigation-api-tokens',
    pageSelector: null,
    caption: 'API tokens — ключи доступа для интеграций',
  },
];

export default menuItems;
