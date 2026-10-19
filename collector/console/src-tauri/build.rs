fn main() {
    // Embed an actual Windows application manifest in the Tauri executable.
    // An NSIS per-machine installer only elevates the installer, not the app
    // the user launches afterwards. requireAdministrator prompts on every
    // launch and prevents running the management console unelevated.
    #[cfg(windows)]
    {
        let attributes = tauri_build::Attributes::new().windows_attributes(
            tauri_build::WindowsAttributes::new().app_manifest(include_str!("windows/admin.manifest")),
        );
        tauri_build::try_build(attributes).expect("failed to build elevated Nexa Collector");
    }

    #[cfg(not(windows))]
    tauri_build::build();
}
