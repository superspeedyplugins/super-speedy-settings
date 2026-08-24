# Super Speedy Settings

This repository is the shared settings submodule bundled by multiple Super Speedy plugins.

- Every functional edit must increment the patch component of the literal settings version in both `super-speedy-settings.php` and `super-speedy-settings-core.php`.
- Keep the candidate version, `$GLOBALS['ssp_settings_selected_version']` and `SuperSpeedySettingsCore::SETTINGS_VERSION` identical. The highest version wins the cross-plugin loader election; an unchanged version can cause another plugin's older copy to hide the edit.
