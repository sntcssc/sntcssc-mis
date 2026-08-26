# Implementation Plan: Meeting Media Track Cleanup & Group/Channel Display Picture Management

## Overview
This plan addresses two critical features:
1. **Meeting Camera & Mic Cleanup**: Guarantee that all webcam, microphone, and screen share tracks are immediately and completely stopped (`track.stop()`) whenever a user leaves a meeting room, unmounts the component, navigates away via Livewire SPA navigation, or closes the browser tab.
2. **Group & Channel Display Picture Management**: Provide complete UI and backend functionality for Group and Channel admins to upload, preview, update, and remove group/channel display pictures directly from the chat info drawer, the edit group modal, and during new group/channel creation.

---

## Proposed Changes

### 1. Meeting Room Media Cleanup & Hardware Release
#### [MODIFY] [⚡meeting-room.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meeting-room.blade.php)
- Add a robust `stopAllMedia()` Alpine method that stops all audio tracks, video tracks, screen sharing tracks, detaches video `srcObject`, and clears local streams.
- Add Alpine `destroy()` lifecycle hook to invoke `stopAllMedia()` when the component unmounts.
- Register event listeners for `livewire:navigating`, `beforeunload`, and `pagehide` to guarantee hardware camera and microphone stop even when navigating away before server response.
- Attach `x-on:click="stopAllMedia()"` to leave and end meeting action buttons.
- Update `muteMicCompletely()` / `stopVideoCompletely()` to cleanly halt hardware tracks where appropriate.

#### [MODIFY] [⚡chat-call-overlay.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/⚡chat-call-overlay.blade.php)
- Add `destroy()` and event listeners for `livewire:navigating` and `beforeunload` calling `cleanupWebRtc()` to ensure 1-on-1 and group call overlay releases camera/mic when leaving or unmounting.

---

### 2. Group & Channel Display Picture Management
#### [MODIFY] [⚡chat.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡chat.blade.php)
- **Component State & Methods**:
  - Add `$removeAvatar = false` flag in Livewire component.
  - Implement `removeGroupAvatar()` method to remove the current conversation avatar.
  - Add `updatedEditAvatar()` with immediate file validation (`image`, max 5MB) and temporary preview support.
  - Implement `saveGroupProfile()` update handling for `$editAvatar` and `$removeAvatar`.
  - Add direct `uploadGroupAvatarDirect()` method allowing quick avatar changes directly from the conversation details drawer.
- **UI Enhancements**:
  - **Info Drawer (Conversation Details)**:
    - Display a stylish avatar change trigger badge/button directly on the group/channel avatar for authorized admins.
    - Provide "Change Picture" / "Remove Picture" actions.
  - **Edit Group Modal (`edit-group-modal`)**:
    - Add an avatar upload section with current image preview, new image file picker, and "Remove Picture" button.
  - **New Group Modal (`new-group-modal`)** & **New Channel Modal (`new-channel-modal`)**:
    - Add avatar upload input fields with live preview so admins can set pictures upon group or channel creation.

---

## Verification Plan

### Automated Tests
- Run Pest test suite:
  ```powershell
  php artisan test --compact --filter=ChatSystemTest
  php artisan test --compact --filter=OnlineMeetingTest
  ```
- Add new Pest test cases in `tests/Feature/ChatSystemTest.php`:
  - Test group admin can update group display avatar and remove group avatar.
  - Test non-admin cannot update group display avatar.
  - Test creating group and channel with avatar upload.
- Run Laravel Pint formatter:
  ```powershell
  vendor/bin/pint --format agent
  ```

### Manual Verification
- Verify meeting room leaves without keeping camera/mic indicator on in browser.
- Verify group/channel avatar upload in chat portal modal and drawer.
