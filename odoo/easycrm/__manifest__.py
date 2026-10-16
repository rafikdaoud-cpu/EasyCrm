{
    'name': 'EasyCRM',
    'version': '18.0.1.0.0',
    'category': 'Sales/CRM',
    'summary': "Telephone prospecting: prospect lists, calls, callbacks, Excel import, statistics",
    'author': 'EasyCRM',
    'license': 'OPL-1',
    'images': ['static/description/banner.png'],
    'price': 0.0,  # TODO: fixer le prix (minimum 9) avant publication
    'currency': 'EUR',
    # 'support': 'votre-adresse@exemple.com',  # TODO: e-mail de support (visible des acheteurs)
    'depends': ['base', 'mail'],
    'data': [
        'security/security.xml',
        'security/ir.model.access.csv',
        'data/status_data.xml',
        'views/prospect_views.xml',
        'data/custom_views.xml',
        'views/call_views.xml',
        'views/config_views.xml',
        'wizard/call_wizard_views.xml',
        'wizard/import_wizard_views.xml',
        'wizard/distribute_wizard_views.xml',
        'views/settings_views.xml',
        'views/menus.xml',
    ],
    'application': True,
    'installable': True,
}
