import ConfirmEmail from "@/components/confirm-email";
export const metadata = {
  title: "Confirm email · Openmindai",
  robots: { index: false, follow: false },
};
export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ account?: string; token?: string }>;
}) {
  const { account = "", token = "" } = await searchParams;
  return <ConfirmEmail account={account} token={token} />;
}
