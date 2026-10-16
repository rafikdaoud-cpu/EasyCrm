# -*- coding: utf-8 -*-
from odoo import fields, models


class ResUsers(models.Model):
    _inherit = 'res.users'

    easycrm_extension = fields.Char(
        "PBX extension", copy=False,
        help="Your extension on the phone system (e.g. 1001). EasyCRM rings this extension first when you click Call.")

    @property
    def SELF_WRITEABLE_FIELDS(self):
        return super().SELF_WRITEABLE_FIELDS + ['easycrm_extension']
