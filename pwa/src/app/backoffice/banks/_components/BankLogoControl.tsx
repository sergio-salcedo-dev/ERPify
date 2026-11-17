"use client";

import { ImageUploadField } from "@/components/erpify";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import {
  IMAGE_UPLOADER_KEY,
  type ImageUploader,
} from "@/context/shared/images/domain/ImageUploader";

interface BankLogoControlProps {
  bankId: string;
  /** The id the bank holds now. Owned by the page, which seeds it from the loaded bank. */
  logoImageId: string | null;
  /** Called with the id the write answered, or `null` after a removal — never after a refetch. */
  onLogoChange: (logoImageId: string | null) => void;
}

/**
 * The bank logo control of the edit screen, shown only to a session holding `bank.write`: a reader
 * without it sees no control at all, and the logo itself is still displayed on the list and the
 * detail. It acts immediately and owns its preview, which is why it sits beside the bank form rather
 * than inside the form's submit.
 */
export function BankLogoControl({
  bankId,
  logoImageId,
  onLogoChange,
}: Readonly<BankLogoControlProps>) {
  // Resolved when a write happens, not on render: a reader without `bank.write` never reaches it.
  const uploader = () => container.get<ImageUploader>(IMAGE_UPLOADER_KEY);

  return (
    <Can permission={Permission.BANK_WRITE}>
      <section className="banks-edit__logo space-y-2" data-testid="banks-edit__logo">
        <h2 className="text-foreground text-sm font-semibold">Logo</h2>
        <ImageUploadField
          imageId={logoImageId}
          onImageChange={onLogoChange}
          onUpload={(file) =>
            uploader().upload(API_ENDPOINTS.BACKOFFICE.BANKS.LOGO_SET(bankId), file)
          }
          onRemove={() => uploader().remove(API_ENDPOINTS.BACKOFFICE.BANKS.LOGO_REMOVE(bankId))}
          label="Bank logo"
          testIdPrefix="banks-edit__logo-field"
        />
      </section>
    </Can>
  );
}
