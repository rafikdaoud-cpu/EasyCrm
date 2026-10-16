from odoo import api, fields, models
from .utils import COUNTRIES, PRESETS


class ResConfigSettings(models.TransientModel):
    _inherit = 'res.config.settings'

    easycrm_company = fields.Char("Company name (EasyCRM)", config_parameter='easycrm.company')
    easycrm_country = fields.Selection(COUNTRIES, string="Number country", default='DZ',
                                       config_parameter='easycrm.country')
    easycrm_cc = fields.Char("International dialing code", config_parameter='easycrm.cc',
                             help="Without \"+\", e.g. 213 for Algeria.")
    easycrm_trunk = fields.Char("National prefix", config_parameter='easycrm.trunk',
                                help="Digit preceding national numbers (0 in Algeria, Morocco, France).")

    @api.onchange('easycrm_country')
    def _onchange_easycrm_country(self):
        p = PRESETS.get(self.easycrm_country or 'DZ', PRESETS['OTHER'])
        self.easycrm_cc = p['cc']
        self.easycrm_trunk = p['trunk']
